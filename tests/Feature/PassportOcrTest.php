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
        ->push(Process::result(output: 'unreadable'))
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
    expect($images)->toHaveCount(4)                  // 3 cleaned sizes, then the original
        ->and(array_slice($images, 0, 3))->not->toContain($photo)
        ->and($images[3])->toBe($photo);
    foreach (array_slice($images, 0, 3) as $tmp) {
        expect(file_exists($tmp))->toBeFalse();       // temp copies removed
    }

    unlink($photo);
});

it('stops at the first read whose check digits pass', function () {
    $photo = tempnam(sys_get_temp_dir(), 'pp').'.png';
    imagepng(imagecreatetruecolor(600, 400), $photo);

    Process::fake(['*' => Process::result(output: "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10\n")]);

    expect(app(PassportOcr::class)->read($photo)['passport_number'])->toBe('L898902C3');
    Process::assertRanTimes(fn () => true, 1);

    unlink($photo);
});

it('crops white page margins before reading', function () {
    // a beige "passport" on a large white page, like a scanned PDF
    $page = tempnam(sys_get_temp_dir(), 'pp').'.png';
    $img = imagecreatetruecolor(2400, 3400);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    imagefilledrectangle($img, 100, 100, 2100, 900, imagecolorallocate($img, 232, 228, 220));
    imagepng($img, $page);

    $sizes = [];
    Process::fake(function ($process) use (&$sizes) {
        [$w, $h] = getimagesize($process->command[1]);
        $sizes[] = round($w / $h, 1);

        return Process::result(output: '');
    });

    app(PassportOcr::class)->read($page);

    expect($sizes[0])->toBe(2.5); // 2001x801 passport area, not the 2400x3400 page

    unlink($page);
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
