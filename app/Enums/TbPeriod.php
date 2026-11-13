<?php

namespace App\Enums;

enum TbPeriod: string
{
    use EnumHelpers;

    case Current = 'current';
    case Prior = 'prior';

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Current year',
            self::Prior => 'Prior year (comparatives)',
        };
    }
}
