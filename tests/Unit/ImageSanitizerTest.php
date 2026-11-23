<?php

use App\Services\ImageSanitizer;

it('re-encodes a valid image to JPEG', function () {
    $img = imagecreatetruecolor(10, 10);
    ob_start();
    imagepng($img);
    $png = ob_get_clean();

    expect(substr(ImageSanitizer::toJpeg($png), 0, 2))->toBe("\xFF\xD8");
});

it('rejects bytes that are not an image', function () {
    expect(fn () => ImageSanitizer::toJpeg('<?php echo 1;'))->toThrow(InvalidArgumentException::class);
});
