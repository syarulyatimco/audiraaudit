<?php

namespace App\Enums;

enum UserStatus: string
{
    use EnumHelpers;

    case Active = 'active';
    case Deactivated = 'deactivated';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Deactivated => 'Deactivated',
        };
    }
}
