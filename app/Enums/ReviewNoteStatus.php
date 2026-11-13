<?php

namespace App\Enums;

enum ReviewNoteStatus: string
{
    use EnumHelpers;

    case Open = 'open';
    case Responded = 'responded';
    case Cleared = 'cleared';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Responded => 'Responded',
            self::Cleared => 'Cleared',
        };
    }
}
