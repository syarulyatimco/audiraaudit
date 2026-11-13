<?php

namespace App\Services\Documents\Scanning;

final class ScanResult
{
    private function __construct(public readonly string $status, public readonly ?string $detail) {}

    public static function clean(): self
    {
        return new self('clean', null);
    }

    public static function infected(string $detail): self
    {
        return new self('infected', $detail);
    }

    public static function failed(string $detail): self
    {
        return new self('failed', $detail);
    }
}
