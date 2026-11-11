<?php

namespace App\Services;

use App\Models\Photo;
use GdImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class PhotoThumbnail
{
    public function generate(int $photoId): void
    {
        DB::transaction(function () use ($photoId) {
            $photo = Photo::lockForMutation($photoId);
            if (! $photo) {
                return;
            }
            $disk = Storage::disk($photo->disk);
            $path = $photo->thumbnailFile();
            $temporary = $path.'.tmp';
            if ($photo->thumbnail_status === 'ready' && $disk->exists($path)) {
                return;
            }
            $source = null;
            $canvas = null;
            $writing = false;
            $published = false;
            try {
                if (! $disk->exists($photo->path) || $disk->size($photo->path) > 10 * 1024 * 1024) {
                    throw new RuntimeException('Thumbnail source is missing or exceeds the size limit.');
                }
                $bytes = $disk->get($photo->path);
                $dimensions = @getimagesizefromstring($bytes ?: '');
                if (! $dimensions || ! in_array($dimensions['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)
                    || $dimensions[0] > 12000 || $dimensions[1] > 12000 || $dimensions[0] * $dimensions[1] > 20000000) {
                    throw new RuntimeException('Thumbnail source is not a supported bounded image.');
                }
                $source = @imagecreatefromstring($bytes);
                if ($source === false) {
                    throw new RuntimeException('Thumbnail source could not be decoded.');
                }
                if ($dimensions['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
                    $exifStream = fopen('php://temp', 'w+b');
                    try {
                        fwrite($exifStream, $bytes);
                        rewind($exifStream);
                        $exif = @exif_read_data($exifStream, 'IFD0');
                    } finally {
                        fclose($exifStream);
                    }
                    $source = $this->orient($source, (int) ($exif['Orientation'] ?? 1));
                }
                $scale = min(1, 480 / max(imagesx($source), imagesy($source)));
                $width = max(1, (int) round(imagesx($source) * $scale));
                $height = max(1, (int) round(imagesy($source) * $scale));
                $canvas = imagecreatetruecolor($width, $height);
                imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
                imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
                ob_start();
                try {
                    if (! imagejpeg($canvas, null, 82)) {
                        throw new RuntimeException('Thumbnail encoding failed.');
                    }
                    $thumbnail = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                $writing = true;
                if (! $disk->put($temporary, $thumbnail)) {
                    throw new RuntimeException('Thumbnail storage failed.');
                }
                if (! $disk->move($temporary, $path)) {
                    throw new RuntimeException('Thumbnail publishing failed.');
                }
                $published = true;
                $photo->update(['thumbnail_status' => 'ready', 'thumbnail_width' => $width, 'thumbnail_height' => $height, 'thumbnail_bytes' => strlen($thumbnail)]);
            } catch (Throwable $exception) {
                if ($writing) {
                    $disk->delete($temporary);
                }
                if ($published) {
                    $disk->delete($path);
                }
                throw $exception;
            } finally {
                if ($source instanceof GdImage) {
                    imagedestroy($source);
                }
                if ($canvas instanceof GdImage) {
                    imagedestroy($canvas);
                }
            }
        }, 1);
    }

    private function orient(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 8 => 90,
            6, 7 => -90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            throw new RuntimeException('Thumbnail orientation failed.');
        }
        imagedestroy($image);

        return $rotated;
    }
}
