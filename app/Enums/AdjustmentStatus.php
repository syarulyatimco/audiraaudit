<?php

namespace App\Enums;

enum AdjustmentStatus: string
{
    use EnumHelpers;

    case Draft = 'draft';
    case Posted = 'posted';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
            self::Voided => 'Voided',
        };
    }
}
