<?php

use App\Services\PassportOcr;
use Illuminate\Support\Facades\Process;

it('runs tesseract and parses the MRZ', function () {
    Process::fake(['*' => Process::result(
        output: "noise\nP<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10\n",
    )]);

    $result = app(PassportOcr::class)->read('/tmp/passport.jpg');

    expect($result['passport_number'])->toBe('L898902C3');
    Process::assertRan(fn ($process) => $process->command[0] === config('visa.tesseract_path')
        && $process->command[1] === '/tmp/passport.jpg');
});

it('returns null when tesseract fails', function () {
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'boom')]);

    expect(app(PassportOcr::class)->read('/tmp/passport.jpg'))->toBeNull();
});

it('returns null when tesseract cannot be started', function () {
    Process::fake(fn () => throw new RuntimeException('tesseract not found'));

    expect(app(PassportOcr::class)->read('/tmp/passport.jpg'))->toBeNull();
});
