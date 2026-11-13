<?php

namespace App\Enums;

enum FirmRole: string
{
    use EnumHelpers;

    case Partner = 'partner';
    case Manager = 'manager';
    case Senior = 'senior';
    case Associate = 'associate';
    case Administrator = 'administrator';

    public function label(): string
    {
        return match ($this) {
            self::Partner => 'Partner',
            self::Manager => 'Manager',
            self::Senior => 'Senior',
            self::Associate => 'Associate',
            self::Administrator => 'Administrator',
        };
    }
}
