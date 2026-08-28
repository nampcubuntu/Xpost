<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\MediaCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\PostMediaResource;
use App\Models\PostMedia;
use App\Support\MediaName;
use App\Support\VideoProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MediaController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return PostMediaResource::collection(
            PostMedia::query()
                ->when($request->query('search'), fn ($q, $t) => $q->where('original_name', 'like', "%{$t}%"))
                // The picker's All / Photo / Video tabs. Filtering on the mime
                // prefix rather than `category` because an animated GIF is stored
                // as tweet_gif but belongs under Photo to anyone browsing.
                ->when($request->query('type') === 'video', fn ($q) => $q->where('mime', 'like', 'video/%'))
                ->when($request->query('type') === 'photo', fn ($q) => $q->where('mime', 'like', 'image/%'))
                ->latest('id')
                ->paginate(min((int) $request->query('per_page', 40), 100))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $maxKb = config('xpost.composer.max_upload_mb') * 1024;

        $request->validate([
            'file' => [
                'required',
                'file',
                "max:{$maxKb}",
                'mimetypes:'.implode(',', array_merge(
                    config('xpost.composer.allowed_image_mimes'),
                    config('xpost.composer.allowed_video_mimes'),
                )),
            ],
            'alt_text' => ['nullable', 'string', 'max:1000'],
            'is_ai_generated' => ['boolean'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        /*
         * An .mp4 carrying only an audio track passes every check above: the
         * extension, the mime type and the size are all a video's. It fails
         * hours later inside a publish worker, as "Media transcoding failed:
         * unknown" against a post whose account looked misconfigured. Say it
         * here, while the file is still in front of whoever chose it.
         */
        if (str_starts_with((string) $file->getMimeType(), 'video/')
            && VideoProbe::inspect($file->getRealPath()) === null) {
            throw ValidationException::withMessages([
                'file' => 'Tệp này không có luồng video (có thể chỉ chứa âm thanh). X sẽ từ chối khi đăng.',
            ]);
        }

        $hash = hash_file('sha256', $file->getRealPath());

        // The same asset uploaded twice reuses the row -- and with it the cached
        // per-app X media_id, so the second campaign skips the upload entirely.
        if ($existing = PostMedia::query()->where('sha256', $hash)->first()) {
            return PostMediaResource::make($existing)->response();
        }

        $disk = config('filesystems.default');
        $path = $file->store('media/'.now()->format('Y/m'), $disk);

        $dimensions = $this->dimensions($file);

        $media = PostMedia::create([
            'uploaded_by' => $request->user()->id,
            'disk' => $disk,
            'path' => $path,
            /*
             * Filed under a number it was given, not the name it arrived with.
             * Files come off phones and downloaders as `video.mp4`,
             * `0.21.21.2.21.21.mp4` or `IMG_0042.jpg`, so the one label the
             * picker shows under each tile is the one that cannot tell them
             * apart. Photos too: two schemes in one column would mean reading
             * each row to know which kind of name it is.
             */
            'original_name' => MediaName::next(),
            'mime' => $file->getMimeType(),
            'category' => MediaCategory::fromMime((string) $file->getMimeType()),
            'size' => $file->getSize(),
            'width' => $dimensions[0],
            'height' => $dimensions[1],
            'sha256' => $hash,
            'alt_text' => $request->input('alt_text'),
            'is_ai_generated' => $request->boolean('is_ai_generated'),
        ]);

        return PostMediaResource::make($media)->response()->setStatusCode(201);
    }

    public function update(Request $request, PostMedia $medium): PostMediaResource
    {
        $data = $request->validate([
            'alt_text' => ['nullable', 'string', 'max:1000'],
            'is_ai_generated' => ['boolean'],
            // Written once by the client after a <video> reports its metadata.
            'width' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'height' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        $medium->fill($data)->save();

        return PostMediaResource::make($medium);
    }

    public function destroy(PostMedia $medium): JsonResponse
    {
        if ($medium->posts()->exists()) {
            return response()->json(['message' => __('xpost.errors.media_in_use')], 422);
        }

        Storage::disk($medium->disk)->delete($medium->path);
        $medium->delete();

        return response()->json(status: 204);
    }

    /**
     * Stream a stored file to the browser.
     *
     * Route-model binding runs PostMedia through TeamScope, so a request for
     * another team's media is a 404 before this method is entered.
     *
     * response()->file() is used rather than Storage::response() because it
     * honours Range requests -- without them a <video> element cannot seek, and
     * some browsers refuse to play at all.
     */
    public function file(PostMedia $medium)
    {
        $disk = Storage::disk($medium->disk);

        abort_unless($disk->exists($medium->path), 404);

        // Remote disks can hand the browser a signed URL and stay out of the
        // data path entirely.
        if (method_exists($disk, 'temporaryUrl') && $medium->disk !== 'local' && $medium->disk !== 'public') {
            return redirect()->away($disk->temporaryUrl($medium->path, now()->addMinutes(30)));
        }

        return response()->file($disk->path($medium->path), [
            'Content-Type' => $medium->mime,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** @return array{0: int|null, 1: int|null} */
    /**
     * @return array{0:int|null, 1:int|null}
     */
    private function dimensions(UploadedFile $file): array
    {
        if (str_starts_with((string) $file->getMimeType(), 'image/')) {
            $size = @getimagesize($file->getRealPath());

            return $size === false ? [null, null] : [$size[0], $size[1]];
        }

        /*
         * Read from the file rather than waiting for the browser to PATCH what
         * a <video> element reported. The client answer arrives late, can not
         * arrive at all, and is absent for anything uploaded through the API --
         * and a video with no dimensions is exactly the case that has to be
         * caught here.
         */
        $probed = VideoProbe::inspect($file->getRealPath());

        return $probed === null ? [null, null] : [$probed['width'] ?: null, $probed['height'] ?: null];
    }
}
