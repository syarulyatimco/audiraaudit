<?php

namespace App\Enums;

enum FirmStatus: string
{
    use EnumHelpers;

    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Trial',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Cancelled => 'Cancelled',
        };
    }
}
