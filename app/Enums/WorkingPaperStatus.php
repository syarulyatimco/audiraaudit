<?php

namespace App\Enums;

enum WorkingPaperStatus: string
{
    use EnumHelpers;

    case Draft = 'draft';
    case Prepared = 'prepared';
    case Reviewed = 'reviewed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Prepared => 'Prepared',
            self::Reviewed => 'Reviewed',
        };
    }
}
