<?php

namespace App\Enums;

enum ReportingFramework: string
{
    use EnumHelpers;

    case Mfrs = 'mfrs';
    case Mpers = 'mpers';
    case Mpsas = 'mpsas';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Mfrs => 'MFRS',
            self::Mpers => 'MPERS',
            self::Mpsas => 'MPSAS',
            self::Other => 'Other framework',
        };
    }
}
