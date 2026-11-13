<?php

namespace App\Enums;

enum RiskLevel: string
{
    use EnumHelpers;

    case FinancialStatement = 'financial_statement';
    case Assertion = 'assertion';

    public function label(): string
    {
        return match ($this) {
            self::FinancialStatement => 'Financial statement level',
            self::Assertion => 'Assertion level',
        };
    }
}
