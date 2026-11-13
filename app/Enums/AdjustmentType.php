<?php

namespace App\Enums;

enum AdjustmentType: string
{
    use EnumHelpers;

    case Audit = 'audit';
    case Reclassification = 'reclassification';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Audit => 'Audit adjustment (AJE)',
            self::Reclassification => 'Reclassification (RJE)',
            self::Client => 'Client adjustment (CJE)',
        };
    }

    public function prefix(): string
    {
        return match ($this) {
            self::Audit => 'AJE',
            self::Reclassification => 'RJE',
            self::Client => 'CJE',
        };
    }
}
