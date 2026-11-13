<?php

namespace App\Enums;

enum MaterialityStage: string
{
    use EnumHelpers;

    case Planning = 'planning';
    case Revised = 'revised';
    case Final = 'final';

    public function label(): string
    {
        return match ($this) {
            self::Planning => 'Planning',
            self::Revised => 'Revised during the audit',
            self::Final => 'Final (completion)',
        };
    }
}
