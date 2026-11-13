<?php

namespace App\Enums;

enum MisstatementClass: string
{
    use EnumHelpers;

    case Factual = 'factual';
    case Judgemental = 'judgemental';
    case Projected = 'projected';

    public function label(): string
    {
        return match ($this) {
            self::Factual => 'Factual',
            self::Judgemental => 'Judgemental',
            self::Projected => 'Projected',
        };
    }
}
