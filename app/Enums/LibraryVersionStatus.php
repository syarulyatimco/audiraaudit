<?php

namespace App\Enums;

enum LibraryVersionStatus: string
{
    use EnumHelpers;

    case Draft = 'draft';
    case Published = 'published';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Superseded => 'Superseded',
        };
    }
}
