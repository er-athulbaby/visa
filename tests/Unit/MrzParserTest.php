<?php

use App\Services\MrzParser;

const SPECIMEN = "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10";

it('computes ICAO check digits', function () {
    expect(MrzParser::checkDigit('L898902C3'))->toBe(6)
        ->and(MrzParser::checkDigit('740812'))->toBe(2)
        ->and(MrzParser::checkDigit('120415'))->toBe(9);
});

it('parses a TD3 passport MRZ', function () {
    expect((new MrzParser)->parse(SPECIMEN))->toBe([
        'full_name' => 'ANNA MARIA ERIKSSON',
        'passport_number' => 'L898902C3',
        'nationality' => 'UTO',
        'issuing_country' => 'UTO',
        'date_of_birth' => '1974-08-12',
        'passport_expiry_date' => '2012-04-15',
    ]);
});

it('finds the MRZ inside noisy OCR output with stray spaces', function () {
    $noisy = "REPUBLIC OF UTOPIA\nPassport\nP<UTOERIKSSON<<ANNA<MARIA<<<<< <<<<<<<<<<<<<<\n L898902C36UTO7408122F1204159ZE184226B<<<<<10 \n";

    expect((new MrzParser)->parse($noisy)['passport_number'])->toBe('L898902C3');
});

it('fixes O/0 confusion in numeric fields', function () {
    $ocr = "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO74O8122F12O4159ZE184226B<<<<<10";

    expect((new MrzParser)->parse($ocr)['date_of_birth'])->toBe('1974-08-12');
});

it('maps the German D code to DEU', function () {
    $line1 = str_pad('P<D<<MUSTERMANN<<ERIKA', 44, '<');
    $number = 'C01X00T47';
    $line2 = $number.MrzParser::checkDigit($number).'D<<'.'640812'.MrzParser::checkDigit('640812').'F'
        .'270810'.MrzParser::checkDigit('270810').str_repeat('<', 14).'<'.'0';

    expect((new MrzParser)->parse("$line1\n$line2"))
        ->toMatchArray(['nationality' => 'DEU', 'issuing_country' => 'DEU']);
});

it('returns null when a check digit fails', function () {
    expect((new MrzParser)->parse(str_replace('L898902C36', 'L898902C37', SPECIMEN)))->toBeNull();
});

it('returns null for text without an MRZ', function () {
    expect((new MrzParser)->parse('just some words'))->toBeNull();
});
