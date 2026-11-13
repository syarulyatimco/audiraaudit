<?php

namespace App\Enums;

enum BenchmarkBasis: string
{
    use EnumHelpers;

    case CurrentYear = 'current_year';
    case PriorYear = 'prior_year';
    case Budget = 'budget';
    case Normalised = 'normalised';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CurrentYear => 'Current-year adjusted figures (AUDIRA)',
            self::PriorYear => 'Prior-year figures (AUDIRA)',
            self::Budget => 'Budget or forecast',
            self::Normalised => 'Normalised figure',
            self::Other => 'Other basis',
        };
    }
}
