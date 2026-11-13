<?php

namespace App\Enums;

enum TbImportStatus: string
{
    use EnumHelpers;

    case Pending = 'pending';
    case Active = 'active';
    case Superseded = 'superseded';
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting column mapping',
            self::Active => 'Active',
            self::Superseded => 'Superseded',
            self::Discarded => 'Discarded',
        };
    }
}
