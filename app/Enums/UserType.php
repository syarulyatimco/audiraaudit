<?php

namespace App\Enums;

enum UserType: string
{
    use EnumHelpers;

    case Platform = 'platform';
    case Firm = 'firm';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Platform => 'Platform',
            self::Firm => 'Firm staff',
            self::Client => 'Client portal',
        };
    }
}
