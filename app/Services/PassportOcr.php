<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class PassportOcr
{
    public function __construct(private MrzParser $parser) {}

    public function read(string $absoluteImagePath): ?array
    {
        try {
            $result = Process::timeout(30)->run([
                config('visa.tesseract_path'), $absoluteImagePath, 'stdout', '--psm', '6',
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

        return $this->parser->parse($result->output());
    }
}
