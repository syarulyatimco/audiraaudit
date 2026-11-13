<?php

use App\Support\Tenancy\TenantContext;

if (! function_exists('money_label')) {
    /** Format integer sen as a currency label, e.g. 1500 → "RM15.00". */
    function money_label(int $sen, string $currency = 'MYR'): string
    {
        $prefix = $currency === 'MYR' ? 'RM' : $currency.' ';

        return $prefix.number_format($sen / 100, 2);
    }
}

if (! function_exists('tenant')) {
    function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }
}

if (! function_exists('local_time')) {
    /** Render a timestamp in the firm display timezone (Malaysia by default). */
    function local_time(?DateTimeInterface $value, string $format = 'j M Y, g:i a'): string
    {
        if ($value === null) {
            return '—';
        }

        return \Illuminate\Support\Carbon::instance($value)->setTimezone(config('audira.display_timezone'))->format($format);
    }
}

if (! function_exists('amount')) {
    /** Accounting display of integer sen: 1,234.56 / (1,234.56) / – for zero. */
    function amount(int|float|null $sen, bool $dashForZero = true): string
    {
        $sen = (int) $sen;
        if ($sen === 0 && $dashForZero) {
            return '–';
        }

        return \App\Services\TrialBalance\AmountParser::format($sen);
    }
}

if (! function_exists('column_letter')) {
    /** 0 → A, 25 → Z, 26 → AA */
    function column_letter(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26).$s;
        }

        return $s;
    }
}
