<?php

namespace App\Enums;

enum CorrectionStatus: string
{
    use EnumHelpers;

    case Proposed = 'proposed';
    case Corrected = 'corrected';
    case Uncorrected = 'uncorrected';

    public function label(): string
    {
        return match ($this) {
            self::Proposed => 'Proposed to management',
            self::Corrected => 'Corrected',
            self::Uncorrected => 'Uncorrected',
        };
    }
}
