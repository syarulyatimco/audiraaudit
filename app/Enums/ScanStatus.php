<?php

namespace App\Enums;

enum ScanStatus: string
{
    use EnumHelpers;

    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Scanning',
            self::Clean => 'Clean',
            self::Infected => 'Blocked',
            self::Failed => 'Scan failed',
        };
    }
}
