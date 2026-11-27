<?php

declare(strict_types=1);

namespace App\Services\X;

use App\Enums\MediaCategory;
use App\Exceptions\XApiException;
use App\Models\PostMedia;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;

/** Prepare a separate, padded video for cards; never change the original asset. */
final class XCardMediaPreparer
{
    private const VERSION = 'v1';

    private const RATIOS = [[2, 3], [4, 5], [191, 100], [1, 1], [9, 16], [16, 9]];

    public function prepare(PostMedia $media): PostMedia
    {
        if (! str_starts_with((string) $media->mime, 'video/')) {
            return $media;
        }

        $fingerprint = hash('sha256', implode('|', [
            self::VERSION, $media->team_id, $media->disk, $media->path, $media->sha256,
        ]));
        $path = 'media/cards/'.self::VERSION.'/'.$fingerprint.'.mp4';
        $disk = Storage::disk($media->disk);
        $probeKey = 'xpost:card-probe:'.$fingerprint;

        if ($prepared = $this->existing($media, $disk, $path)) {
            return $prepared;
        }

        $probe = Cache::get($probeKey);

        if (is_array($probe) && $this->compatible($probe)) {
            return $media;
        }

        $timeout = max(1, min(120, (int) config('xpost.media.conversion_timeout', 120)));

        try {
            return Cache::lock('xpost:card-prepare:'.$fingerprint, $timeout + 60)
                ->block($timeout + 30, function () use ($media, $disk, $path, $probeKey, $timeout): PostMedia {
                    if ($prepared = $this->existing($media, $disk, $path)) {
                        return $prepared;
                    }

                    $temporary = [];

                    try {
                        $probe = Cache::get($probeKey);
                        $source = null;

                        if (! is_array($probe)) {
                            $source = $this->localSource($media, $disk, $temporary);
                            $probe = $this->probe($source);
                            Cache::put($probeKey, $probe, now()->addDays(7));
                        }

                        if ($this->compatible($probe)) {
                            return $media;
                        }

                        $source ??= $this->localSource($media, $disk, $temporary);
                        $output = $this->temporaryFile($temporary);
                        [$width, $height] = $this->canvas($probe);
                        $this->convert($source, $output, $probe, $width, $height, $timeout);

                        $size = filesize($output);

                        if ($size === false || $size === 0) {
                            throw $this->failure('FFmpeg không tạo được video đã thêm viền.');
                        }

                        $metadata = [
                            'width' => $width,
                            'height' => $height,
                            'size' => $size,
                            'sha256' => hash_file('sha256', $output),
                        ];
                        $stream = fopen($output, 'rb');

                        try {
                            if ($stream === false || ! $disk->put($path, $stream, ['visibility' => 'private'])) {
                                throw $this->failure('Không lưu được video đã thêm viền vào kho media.');
                            }
                        } finally {
                            if (is_resource($stream)) {
                                fclose($stream);
                            }
                        }

                        // Publish metadata last: another worker never treats a partial file as ready.
                        if (! $disk->put($path.'.json', json_encode($metadata, JSON_THROW_ON_ERROR), ['visibility' => 'private'])) {
                            throw $this->failure('Không lưu được thông tin video đã thêm viền.');
                        }

                        return $this->descriptor($media, $path, $metadata);
                    } finally {
                        foreach ($temporary as $file) {
                            @unlink($file);
                        }
                    }
                });
        } catch (LockTimeoutException $e) {
            throw $this->failure('Video này đang được xử lý ở tiến trình khác. Hãy thử đăng lại sau khi xử lý xong.', $e);
        } catch (XApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw $this->failure('Không chuẩn bị được video cho card. Kiểm tra kho media và FFmpeg/ffprobe trên máy chạy worker.', $e);
        }
    }

    private function existing(PostMedia $media, FilesystemAdapter $disk, string $path): ?PostMedia
    {
        if (! $disk->exists($path.'.json') || ! $disk->exists($path)) {
            return null;
        }

        $metadata = json_decode((string) $disk->get($path.'.json'), true);

        if (! is_array($metadata)
            || ($metadata['width'] ?? 0) <= 0 || ($metadata['height'] ?? 0) <= 0
            || ($metadata['size'] ?? 0) <= 0 || empty($metadata['sha256'])
            || $disk->size($path) !== $metadata['size']) {
            return null;
        }

        return $this->descriptor($media, $path, $metadata);
    }

    private function descriptor(PostMedia $media, string $path, array $metadata): PostMedia
    {
        // This is an upload descriptor only. Its original model owns cached X IDs.
        return $media->replicate()->forceFill($metadata + [
            'path' => $path,
            'mime' => 'video/mp4',
            'category' => MediaCategory::AmplifyVideo,
            'x_media_ids' => [],
        ]);
    }

    /** @param list<string> $temporary */
    private function localSource(PostMedia $media, FilesystemAdapter $disk, array &$temporary): string
    {
        if (! $disk->exists($media->path)) {
            throw $this->failure('Không tìm thấy video gốc trong kho media.');
        }

        if ($disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return $disk->path($media->path);
        }

        $path = $this->temporaryFile($temporary);
        $source = $disk->readStream($media->path);
        $destination = fopen($path, 'wb');

        try {
            if ($source === false || $destination === false || stream_copy_to_stream($source, $destination) === false) {
                throw $this->failure('Không đọc được video từ kho media để thêm viền.');
            }
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($destination)) {
                fclose($destination);
            }
        }

        return $path;
    }

    /** @param list<string> $temporary */
    private function temporaryFile(array &$temporary): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xpost-card-');

        if ($path === false) {
            throw $this->failure('Không tạo được tệp tạm để xử lý video.');
        }

        $temporary[] = $path;

        return $path;
    }

    /** @return array{width: float, height: float, square_pixels: bool} Display dimensions. */
    private function probe(string $source): array
    {
        try {
            $result = Process::timeout(20)->run([
                (string) config('xpost.media.ffprobe_binary', 'ffprobe'),
                '-v', 'error', '-protocol_whitelist', 'file,pipe', '-select_streams', 'V:0',
                '-show_entries', 'stream=width,height,sample_aspect_ratio:stream_tags=rotate:stream_side_data=rotation',
                '-of', 'json', $source,
            ]);
        } catch (\Throwable $e) {
            throw $this->failure('Không chạy được ffprobe. Cài FFmpeg và cấu hình đường dẫn ffprobe trên máy chạy worker.', $e);
        }

        if (! $result->successful()) {
            throw $this->failure('Không đọc được kích thước video. Kiểm tra ffprobe và tệp video gốc.');
        }

        $stream = data_get(json_decode($result->output(), true), 'streams.0', []);
        $width = (float) ($stream['width'] ?? 0);
        $height = (float) ($stream['height'] ?? 0);
        $sar = explode(':', (string) ($stream['sample_aspect_ratio'] ?? '1:1'));
        $squarePixels = true;

        if (count($sar) === 2 && is_numeric($sar[0]) && is_numeric($sar[1]) && (float) $sar[0] > 0 && (float) $sar[1] > 0) {
            $width *= (float) $sar[0] / (float) $sar[1];
            $squarePixels = (float) $sar[0] === (float) $sar[1];
        }

        $rotation = (float) ($stream['tags']['rotate'] ?? 0);
        foreach ($stream['side_data_list'] ?? [] as $data) {
            if (isset($data['rotation'])) {
                $rotation = (float) $data['rotation'];
                break;
            }
        }

        if (((int) round(abs($rotation) / 90)) % 2 === 1) {
            [$width, $height] = [$height, $width];
        }

        if ($width <= 0 || $height <= 0 || ! is_finite($width) || ! is_finite($height)) {
            throw $this->failure('Không xác định được kích thước luồng video để tạo card.');
        }

        return ['width' => $width, 'height' => $height, 'square_pixels' => $squarePixels];
    }

    private function compatible(array $probe): bool
    {
        // Encode anamorphic sources with square pixels so both the stored
        // dimensions and display ratio meet the card requirements.
        if (! ($probe['square_pixels'] ?? false)) {
            return false;
        }

        foreach (self::RATIOS as [$width, $height]) {
            // Only numerical roundoff is tolerated, not a visibly different ratio.
            if (abs($probe['width'] / $probe['height'] - $width / $height) < 0.000001) {
                return true;
            }
        }

        return false;
    }

    /** @return array{int, int} */
    private function canvas(array $probe): array
    {
        $best = null;
        $smallestArea = INF;

        foreach (self::RATIOS as [$width, $height]) {
            // An even multiplier guarantees even dimensions and an exact supported ratio.
            $multiple = max(2, (int) ceil(max($probe['width'] / $width, $probe['height'] / $height) / 2) * 2);
            $area = $width * $height * $multiple * $multiple;

            if ($area < $smallestArea) {
                $smallestArea = $area;
                $best = [$width * $multiple, $height * $multiple];
            }
        }

        return $best;
    }

    private function convert(string $source, string $output, array $probe, int $width, int $height, int $timeout): void
    {
        $contentWidth = max(2, (int) ceil($probe['width'] / 2) * 2);
        $contentHeight = max(2, (int) ceil($probe['height'] / 2) * 2);
        $filter = "scale={$contentWidth}:{$contentHeight}:force_original_aspect_ratio=decrease:force_divisible_by=2:reset_sar=1"
            .",pad={$width}:{$height}:(ow-iw)/2:(oh-ih)/2:color=black,setsar=1";

        try {
            $result = Process::timeout($timeout)->run([
                (string) config('xpost.media.ffmpeg_binary', 'ffmpeg'),
                '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-protocol_whitelist', 'file,pipe', '-i', $source,
                '-map', '0:V:0', '-map', '0:a:0?', '-vf', $filter,
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', '-threads', '2',
                '-c:a', 'aac', '-b:a', '128k', '-map_metadata', '-1', '-metadata:s:v:0', 'rotate=0',
                '-movflags', '+faststart', '-f', 'mp4', $output,
            ]);
        } catch (\Throwable $e) {
            throw $this->failure('Không chạy xong FFmpeg để thêm viền video. Kiểm tra FFmpeg và thời gian xử lý trên máy chạy worker.', $e);
        }

        if (! $result->successful()) {
            throw $this->failure('FFmpeg không thêm được viền video. Cần FFmpeg 8 trở lên có bộ mã hóa H.264/AAC. '
                .mb_substr(trim($result->errorOutput()), 0, 300));
        }
    }

    private function failure(string $message, ?\Throwable $previous = null): XApiException
    {
        return new XApiException($message, errorCode: 'card_media_preparation_failed', previous: $previous);
    }
}
