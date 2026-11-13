<?php

namespace App\Services\TrialBalance;

use InvalidArgumentException;

/**
 * Converts accounting-formatted amounts into integer sen without floating-point drift.
 * Accepts: 1234.56 · 1,234.56 · RM 1,234.56 · (1,234.56) · 1,234.56- · -1234.56 · 1,234.56 CR/DR · "" · "-"
 */
final class AmountParser
{
    public static function toSen(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }
        if (is_int($value)) {
            return $value * 100;
        }
        if (is_float($value)) {
            return (int) round($value * 100);
        }

        $s = strtoupper(trim((string) $value));
        $s = str_replace(["\u{00A0}", ' ', ',', 'RM', 'MYR'], '', $s);
        if ($s === '' || $s === '-' || $s === '–') {
            return 0;
        }

        $negative = false;
        if (str_ends_with($s, 'CR')) {
            $negative = true;
            $s = substr($s, 0, -2);
        } elseif (str_ends_with($s, 'DR')) {
            $s = substr($s, 0, -2);
        }
        if (preg_match('/^\((.*)\)$/', $s, $m)) {
            $negative = ! $negative;
            $s = $m[1];
        }
        if (str_ends_with($s, '-')) {
            $negative = ! $negative;
            $s = substr($s, 0, -1);
        }
        if (str_starts_with($s, '-')) {
            $negative = ! $negative;
            $s = substr($s, 1);
        }

        if (preg_match('/^\d+(\.\d{1,2})?$/', $s)) {
            [$whole, $frac] = array_pad(explode('.', $s), 2, '');
            $sen = (int) $whole * 100 + (int) str_pad($frac, 2, '0');
        } elseif (preg_match('/^\d+\.\d+(E[+-]?\d+)?$|^\d+E[+-]?\d+$/', $s)) {
            // Spreadsheet float (e.g. 1234.5599999999) — round half away from zero to the sen.
            $sen = (int) round(((float) $s) * 100);
        } else {
            throw new InvalidArgumentException("\"{$value}\" is not a valid amount");
        }

        if ($sen > 9_000_000_000_000_000) {
            throw new InvalidArgumentException("\"{$value}\" is too large");
        }

        return $negative ? -$sen : $sen;
    }

    public static function format(int $sen, bool $parenthesesForNegative = true): string
    {
        $abs = number_format(abs($sen) / 100, 2);
        if ($sen < 0) {
            return $parenthesesForNegative ? "({$abs})" : "-{$abs}";
        }

        return $abs;
    }
}
