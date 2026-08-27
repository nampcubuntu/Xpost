<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Does this file actually contain a video track, and how big is it?
 *
 * Both questions used to be left to the browser: the composer read a <video>
 * element's metadata and PATCHed the answer back. That works right up until
 * the file has no video in it -- an .mp4 carrying only an AAC track uploads
 * cleanly, reports no dimensions, and fails hours later inside a publish
 * worker as "Media transcoding failed: unknown", by which point nothing on
 * screen connects the error to the file.
 *
 * Answering it at upload needs no ffmpeg on the host: uploads are limited to
 * MP4 and QuickTime, which are the same ISO base media container, and the
 * handler type of each track is a fixed offset inside its `hdlr` box.
 *
 * Only the box headers are read. `mdat` -- the payload, and effectively the
 * whole file -- is seeked over, so a 500MB upload costs a few dozen reads.
 */
final class VideoProbe
{
    /** Containers worth descending into on the way to tkhd and hdlr. */
    private const CONTAINERS = ['moov', 'trak', 'mdia'];

    /** Stop rather than walk a pathological file forever. */
    private const MAX_BOXES = 5_000;

    private int $boxesRead = 0;

    /**
     * @return array{width:int, height:int}|null null when there is no video track
     */
    public static function inspect(string $path): ?array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            return (new self)->walk($handle, 0, self::fileSize($handle), null);
        } catch (\Throwable) {
            // A file this cannot parse is not proof of anything; let the rest of
            // the pipeline decide.
            return null;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Walk one level of boxes, carrying the dimensions of the track being read.
     *
     * @param  resource  $handle
     * @param  array{width:int, height:int}|null  $trackSize  tkhd of the enclosing trak
     * @return array{width:int, height:int}|null
     */
    private function walk($handle, int $start, int $end, ?array $trackSize): ?array
    {
        $offset = $start;

        while ($offset < $end - 8) {
            if (++$this->boxesRead > self::MAX_BOXES) {
                return null;
            }

            fseek($handle, $offset);
            $header = fread($handle, 8);

            if ($header === false || strlen($header) < 8) {
                return null;
            }

            ['size' => $size, 'type' => $type] = unpack('Nsize/a4type', $header);
            $payload = $offset + 8;

            if ($size === 1) {
                // 64-bit size, in the eight bytes after the type.
                $large = fread($handle, 8);
                $size = self::readUnsigned64((string) $large);
                $payload += 8;
            } elseif ($size === 0) {
                // Runs to the end of the file.
                $size = $end - $offset;
            }

            if ($size < 8 || $offset + $size > $end) {
                return null;
            }

            $result = $this->enter($handle, $type, $payload, $offset + $size, $trackSize);

            if ($result !== null) {
                return $result;
            }

            $offset += $size;
        }

        return null;
    }

    /**
     * @param  resource  $handle
     * @param  array{width:int, height:int}|null  $trackSize
     * @return array{width:int, height:int}|null
     */
    private function enter($handle, string $type, int $payload, int $end, ?array $trackSize): ?array
    {
        /*
         * A trak carries its size in tkhd and its kind in mdia/hdlr, in that
         * order -- so the size is read on the way past and only returned once
         * the handler confirms the track is video. An audio track has a tkhd
         * too, with width and height both zero.
         */
        if ($type === 'trak') {
            return $this->walk($handle, $payload, $end, $this->trackHeader($handle, $payload, $end));
        }

        if (in_array($type, self::CONTAINERS, true)) {
            return $this->walk($handle, $payload, $end, $trackSize);
        }

        if ($type === 'hdlr' && $this->handlerType($handle, $payload) === 'vide') {
            // A video track with a zero-sized tkhd is still a video track; the
            // dimensions are a bonus, not the answer.
            return $trackSize ?? ['width' => 0, 'height' => 0];
        }

        return null;
    }

    /**
     * tkhd's width and height, as 16.16 fixed point after a variable prefix.
     *
     * @param  resource  $handle
     * @return array{width:int, height:int}|null
     */
    private function trackHeader($handle, int $start, int $end): ?array
    {
        $offset = $start;

        while ($offset < $end - 8) {
            fseek($handle, $offset);
            $header = fread($handle, 8);

            if ($header === false || strlen($header) < 8) {
                return null;
            }

            ['size' => $size, 'type' => $type] = unpack('Nsize/a4type', $header);

            if ($size < 8) {
                return null;
            }

            if ($type === 'tkhd') {
                $version = ord((string) fread($handle, 1));

                // version(1) flags(3), then 20 bytes at v0 or 32 at v1, then
                // reserved(8) layer(2) alternate_group(2) volume(2)
                // reserved(2) matrix(36) -- width and height come last.
                fseek($handle, $offset + 8 + ($version === 1 ? 88 : 76));
                $dimensions = fread($handle, 8);

                if ($dimensions === false || strlen($dimensions) < 8) {
                    return null;
                }

                $values = unpack('Nwidth/Nheight', $dimensions);

                // 16.16 fixed point: the integer part is the high half.
                return ['width' => $values['width'] >> 16, 'height' => $values['height'] >> 16];
            }

            $offset += $size;
        }

        return null;
    }

    /** @param  resource  $handle */
    private function handlerType($handle, int $payload): string
    {
        // version(1) flags(3) pre_defined(4), then the handler type.
        fseek($handle, $payload + 8);

        return (string) fread($handle, 4);
    }

    /** @param  resource  $handle */
    private static function fileSize($handle): int
    {
        $stat = fstat($handle);

        return (int) ($stat['size'] ?? 0);
    }

    private static function readUnsigned64(string $bytes): int
    {
        if (strlen($bytes) < 8) {
            return 0;
        }

        ['high' => $high, 'low' => $low] = unpack('Nhigh/Nlow', $bytes);

        return ($high << 32) | $low;
    }
}
