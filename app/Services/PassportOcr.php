<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class PassportOcr
{
    public function __construct(private MrzParser $parser) {}

    /** Reads a passport photo/scan (JPG, PNG, or the first page of a PDF). */
    public function read(string $absolutePath): ?array
    {
        if (@mime_content_type($absolutePath) !== 'application/pdf') {
            return $this->readImage($absolutePath);
        }

        $page = $this->renderFirstPdfPage($absolutePath);
        if ($page === null) {
            return null;
        }

        try {
            return $this->readImage($page);
        } finally {
            @unlink($page);
        }
    }

    private function readImage(string $absoluteImagePath): ?array
    {
        $cleaned = $this->cleanUp($absoluteImagePath);

        try {
            // Phone photos read better cleaned up; the original is the fallback.
            foreach (array_filter([$cleaned, $absoluteImagePath]) as $image) {
                $text = $this->tesseract($image);
                if ($text === null) {
                    return null; // tesseract itself is broken; trying again won't help
                }
                if ($fields = $this->parser->parse($text)) {
                    return $fields;
                }
            }

            return null;
        } finally {
            if ($cleaned) {
                @unlink($cleaned);
            }
        }
    }

    /** Renders page 1 at 300 dpi with Poppler's pdftoppm; returns a temp PNG path or null. */
    private function renderFirstPdfPage(string $pdf): ?string
    {
        $prefix = tempnam(sys_get_temp_dir(), 'pdf');
        @unlink($prefix); // pdftoppm writes <prefix>.png

        try {
            $result = Process::timeout(30)->run([
                config('visa.pdftoppm_path'), '-png', '-r', '300', '-f', '1', '-l', '1', '-singlefile', $pdf, $prefix,
            ]);
        } catch (\Throwable $e) {
            report($e); // Poppler missing: fall back to manual entry

            return null;
        }

        $png = $prefix.'.png';
        if ($result->failed() || ! is_file($png)) {
            report(new \RuntimeException('pdftoppm failed: '.$result->errorOutput()));
            @unlink($png);

            return null;
        }

        return $png;
    }

    private function tesseract(string $image): ?string
    {
        try {
            $result = Process::timeout(30)->run([
                config('visa.tesseract_path'), $image, 'stdout',
                '--tessdata-dir', config('visa.tessdata_dir'),
                '-l', config('visa.tesseract_lang'),
                '--psm', '6',
            ]);
        } catch (\Throwable $e) {
            // Missing binary or bad path: OCR must never block the applicant form.
            report($e);

            return null;
        }

        if ($result->failed()) {
            report(new \RuntimeException('Tesseract failed: '.$result->errorOutput()));

            return null;
        }

        return $result->output();
    }

    /** Grayscale, stronger contrast and enlarged small photos; returns a temp PNG path or null. */
    private function cleanUp(string $path): ?string
    {
        if (! is_file($path) || ! ($bytes = @file_get_contents($path))) {
            return null;
        }

        set_error_handler(fn () => true);
        try {
            $img = imagecreatefromstring($bytes);
        } finally {
            restore_error_handler();
        }
        if ($img === false) {
            return null;
        }

        // ponytail: fixed 1600px target and contrast; tune here if real passport photos read poorly.
        if (imagesx($img) < 1600) {
            $img = imagescale($img, 1600) ?: $img;
        }
        imagefilter($img, IMG_FILTER_GRAYSCALE);
        imagefilter($img, IMG_FILTER_CONTRAST, -40);

        $out = tempnam(sys_get_temp_dir(), 'mrz').'.png';
        imagepng($img, $out);

        return $out;
    }
}
