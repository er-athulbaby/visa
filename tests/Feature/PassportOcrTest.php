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

it('uses the bundled MRZ model', function () {
    Process::fake(['*' => Process::result(output: '')]);

    app(PassportOcr::class)->read('/tmp/passport.jpg');

    Process::assertRan(function ($process) {
        $cmd = $process->command;
        $dir = array_search('--tessdata-dir', $cmd, true);
        $lang = array_search('-l', $cmd, true);

        return $dir !== false && $cmd[$dir + 1] === config('visa.tessdata_dir')
            && $lang !== false && $cmd[$lang + 1] === 'mrz';
    });
});

it('reads a cleaned-up copy of the photo first, then falls back to the original', function () {
    $photo = tempnam(sys_get_temp_dir(), 'pp').'.png';
    $img = imagecreatetruecolor(600, 400);
    imagefill($img, 0, 0, imagecolorallocate($img, 230, 225, 215));
    imagepng($img, $photo);

    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: 'unreadable'))
        ->push(Process::result(output: "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10\n")),
    ]);

    $result = app(PassportOcr::class)->read($photo);

    expect($result['passport_number'])->toBe('L898902C3');
    $images = [];
    Process::assertRan(function ($process) use (&$images) {
        $images[] = $process->command[1];

        return true;
    });
    expect($images)->toHaveCount(2)
        ->and($images[0])->not->toBe($photo)   // cleaned copy first
        ->and($images[1])->toBe($photo)        // original as fallback
        ->and(file_exists($images[0]))->toBeFalse(); // temp copy removed

    unlink($photo);
});

function fakePdf(): string
{
    $pdf = tempnam(sys_get_temp_dir(), 'pp').'.pdf';
    file_put_contents($pdf, "%PDF-1.4\n%fake\n");

    return $pdf;
}

it('turns the first page of a PDF into an image, then reads it', function () {
    $pdf = fakePdf();
    $rendered = null;

    Process::fake(function ($process) use (&$rendered) {
        if ($process->command[0] === config('visa.pdftoppm_path')) {
            $rendered = end($process->command).'.png';   // pdftoppm -singlefile writes <prefix>.png
            $img = imagecreatetruecolor(400, 300);
            imagepng($img, $rendered);

            return Process::result();
        }

        return Process::result(output: "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10\n");
    });

    $result = app(PassportOcr::class)->read($pdf);

    expect($result['passport_number'])->toBe('L898902C3');
    Process::assertRan(fn ($p) => $p->command[0] === config('visa.pdftoppm_path')
        && in_array('-singlefile', $p->command, true) && in_array($pdf, $p->command, true));
    Process::assertRan(fn ($p) => $p->command[0] === config('visa.tesseract_path') && $p->command[1] !== $pdf);
    expect(file_exists($rendered))->toBeFalse(); // rendered page cleaned up

    unlink($pdf);
});

it('returns null for a PDF when pdftoppm is not available', function () {
    $pdf = fakePdf();
    Process::fake(fn () => throw new RuntimeException('pdftoppm not found'));

    expect(app(PassportOcr::class)->read($pdf))->toBeNull();

    unlink($pdf);
});

it('returns null when tesseract fails', function () {
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'boom')]);

    expect(app(PassportOcr::class)->read('/tmp/passport.jpg'))->toBeNull();
});

it('returns null when tesseract cannot be started', function () {
    Process::fake(fn () => throw new RuntimeException('tesseract not found'));

    expect(app(PassportOcr::class)->read('/tmp/passport.jpg'))->toBeNull();
});
