<?php

namespace App\Enums;

enum SampleTestType: string
{
    use EnumHelpers;

    case Monetary = 'monetary';
    case Attributes = 'attributes';

    public function label(): string
    {
        return match ($this) {
            self::Monetary => 'Monetary (substantive test of details)',
            self::Attributes => 'Attributes (test of controls)',
        };
    }
}
