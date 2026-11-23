<?php

namespace App\Services;

class ImageSanitizer
{
    /** Decode + re-encode so metadata and anything smuggled into the file is dropped. */
    public static function toJpeg(string $bytes, int $quality = 90): string
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new \InvalidArgumentException('Not a valid image.');
        }

        ob_start();
        imagejpeg($image, null, $quality);

        return ob_get_clean();
    }
}
