<?php
declare(strict_types=1);

namespace App\Domain\Foods;

/** GTIN helpers for barcodes (EAN-8, UPC-A, EAN-13, GTIN-14). Pure. */
final class Gtin
{
    public static function clean(string $raw): string
    {
        return preg_replace('/\D/', '', $raw) ?? '';
    }

    /** Length 8/12/13/14 and a correct check digit. A misread from the camera almost always fails this. */
    public static function valid(string $code): bool
    {
        $n = strlen($code);
        if (!in_array($n, [8, 12, 13, 14], true) || !ctype_digit($code)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < $n - 1; $i++) {
            // weights 3,1,3,1... counting from the digit next to the check digit
            $sum += (int) $code[$n - 2 - $i] * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === (int) $code[$n - 1];
    }

    /**
     * The same product can be stored as UPC-A (12), EAN-13 with a leading zero, or GTIN-14. Candidate spellings to look up,
     * the scanned one first.
     * @return list<string>
     */
    public static function variants(string $code): array
    {
        $out = [$code];
        $core = ltrim($code, '0');
        foreach ([8, 12, 13, 14] as $len) {
            if (strlen($core) <= $len) {
                $out[] = str_pad($core, $len, '0', STR_PAD_LEFT);
            }
        }

        return array_values(array_unique($out));
    }
}
