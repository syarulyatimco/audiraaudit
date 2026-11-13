<?php

namespace App\Enums;

enum FindingStatus: string
{
    use EnumHelpers;

    case Open = 'open';
    case Resolved = 'resolved';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Resolved => 'Resolved',
            self::Withdrawn => 'Withdrawn',
        };
    }
}
