<?php

namespace App\Enums;

enum FindingType: string
{
    use EnumHelpers;

    case Misstatement = 'misstatement';
    case ControlDeficiency = 'control_deficiency';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Misstatement => 'Misstatement',
            self::ControlDeficiency => 'Control deficiency',
            self::Other => 'Other matter',
        };
    }
}
