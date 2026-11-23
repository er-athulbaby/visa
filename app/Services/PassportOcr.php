<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class PassportOcr
{
    public function __construct(private MrzParser $parser) {}

    public function read(string $absoluteImagePath): ?array
    {
        $result = Process::timeout(30)->run([
            config('visa.tesseract_path'), $absoluteImagePath, 'stdout', '--psm', '6',
        ]);

        if ($result->failed()) {
            report(new \RuntimeException('Tesseract failed: '.$result->errorOutput()));

            return null;
        }

        return $this->parser->parse($result->output());
    }
}
