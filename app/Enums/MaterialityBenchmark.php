<?php

namespace App\Enums;

enum MaterialityBenchmark: string
{
    use EnumHelpers;

    case ProfitBeforeTax = 'profit_before_tax';
    case Revenue = 'revenue';
    case TotalAssets = 'total_assets';
    case TotalExpenses = 'total_expenses';
    case Equity = 'equity';
    case GrossProfit = 'gross_profit';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ProfitBeforeTax => 'Profit before tax',
            self::Revenue => 'Revenue',
            self::TotalAssets => 'Total assets',
            self::TotalExpenses => 'Total expenses',
            self::Equity => 'Equity / net assets',
            self::GrossProfit => 'Gross profit',
            self::Other => 'Other benchmark',
        };
    }
}
