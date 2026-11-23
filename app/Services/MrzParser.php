<?php

namespace App\Services;

/**
 * Parses an ICAO 9303 TD3 (passport) machine-readable zone out of raw OCR text.
 */
class MrzParser
{
    private const LINE_LENGTH = 44;

    public static function checkDigit(string $value): int
    {
        $weights = [7, 3, 1];
        $sum = 0;

        foreach (str_split($value) as $i => $char) {
            $v = match (true) {
                ctype_digit($char) => (int) $char,
                $char === '<' => 0,
                default => ord($char) - 55, // A=10 … Z=35
            };
            $sum += $v * $weights[$i % 3];
        }

        return $sum % 10;
    }

    /** @return array{full_name:string,passport_number:string,nationality:string,issuing_country:string,date_of_birth:?string,passport_expiry_date:?string}|null */
    public function parse(string $ocrText): ?array
    {
        $lines = $this->findLines($ocrText);
        if ($lines === null) {
            return null;
        }
        [$l1, $l2] = $lines;

        $number = substr($l2, 0, 9);
        $dob = $this->digits(substr($l2, 13, 6));
        $expiry = $this->digits(substr($l2, 21, 6));

        $valid = self::checkDigit($number) === (int) $this->digits($l2[9])
            && self::checkDigit($dob) === (int) $this->digits($l2[19])
            && self::checkDigit($expiry) === (int) $this->digits($l2[27]);

        if (! $valid) {
            return null;
        }

        [$surname, $given] = array_pad(explode('<<', substr($l1, 5), 2), 2, '');
        $name = trim(preg_replace('/\s+/', ' ', str_replace('<', ' ', $given.' '.$surname)));

        return [
            'full_name' => $name,
            'passport_number' => rtrim($number, '<'),
            'nationality' => $this->country(substr($l2, 10, 3)),
            'issuing_country' => $this->country(substr($l1, 2, 3)),
            'date_of_birth' => $this->date($dob, future: false),
            'passport_expiry_date' => $this->date($expiry, future: true),
        ];
    }

    /** @return array{0:string,1:string}|null */
    private function findLines(string $text): ?array
    {
        $candidates = [];
        foreach (preg_split('/\R/', strtoupper($text)) as $line) {
            $line = preg_replace('/\s+/', '', $line);
            if (strlen($line) >= 40 && preg_match('/^[A-Z0-9<]+$/', $line)) {
                $candidates[] = substr(str_pad($line, self::LINE_LENGTH, '<'), 0, self::LINE_LENGTH);
            }
        }

        foreach ($candidates as $i => $line) {
            if ($line[0] === 'P' && isset($candidates[$i + 1])) {
                return [$line, $candidates[$i + 1]];
            }
        }

        return null;
    }

    /** OCR often reads digits as look-alike letters in numeric-only fields. */
    private function digits(string $value): string
    {
        return strtr($value, ['O' => '0', 'Q' => '0', 'D' => '0', 'I' => '1', 'L' => '1', 'Z' => '2', 'S' => '5', 'B' => '8']);
    }

    private function country(string $code): string
    {
        $code = str_replace('<', '', $code);

        return $code === 'D' ? 'DEU' : $code;
    }

    private function date(string $yymmdd, bool $future): ?string
    {
        if (! ctype_digit($yymmdd)) {
            return null;
        }

        $yy = (int) substr($yymmdd, 0, 2);
        // Controller ruling: date('y') rather than now(), so this works without the Laravel app booted (tests/Unit).
        $century = $future || $yy <= (int) date('y') ? 2000 : 1900;
        [$y, $m, $d] = [$century + $yy, (int) substr($yymmdd, 2, 2), (int) substr($yymmdd, 4, 2)];

        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }
}
