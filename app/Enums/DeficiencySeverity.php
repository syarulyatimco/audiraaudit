<?php

namespace App\Enums;

enum DeficiencySeverity: string
{
    use EnumHelpers;

    case Deficiency = 'deficiency';
    case Significant = 'significant_deficiency';

    public function label(): string
    {
        return match ($this) {
            self::Deficiency => 'Deficiency in internal control',
            self::Significant => 'Significant deficiency',
        };
    }
}
