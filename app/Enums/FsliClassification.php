<?php

namespace App\Enums;

enum FsliClassification: string
{
    use EnumHelpers;

    case NonCurrentAsset = 'non_current_asset';
    case CurrentAsset = 'current_asset';
    case Equity = 'equity';
    case NonCurrentLiability = 'non_current_liability';
    case CurrentLiability = 'current_liability';
    case Revenue = 'revenue';
    case CostOfSales = 'cost_of_sales';
    case OtherIncome = 'other_income';
    case Expense = 'expense';
    case FinanceCost = 'finance_cost';
    case TaxExpense = 'tax_expense';

    public function label(): string
    {
        return match ($this) {
            self::NonCurrentAsset => 'Non-current assets',
            self::CurrentAsset => 'Current assets',
            self::Equity => 'Equity',
            self::NonCurrentLiability => 'Non-current liabilities',
            self::CurrentLiability => 'Current liabilities',
            self::Revenue => 'Revenue',
            self::CostOfSales => 'Cost of sales',
            self::OtherIncome => 'Other income',
            self::Expense => 'Operating expenses',
            self::FinanceCost => 'Finance costs',
            self::TaxExpense => 'Income tax expense',
        };
    }

    /** sofp = statement of financial position, pl = statement of profit or loss */
    public function statement(): string
    {
        return in_array($this, [self::NonCurrentAsset, self::CurrentAsset, self::Equity, self::NonCurrentLiability, self::CurrentLiability], true) ? 'sofp' : 'pl';
    }

    /** +1 debit-normal (assets, expenses), -1 credit-normal (equity, liabilities, income). */
    public function sign(): int
    {
        return in_array($this, [self::NonCurrentAsset, self::CurrentAsset, self::CostOfSales, self::Expense, self::FinanceCost, self::TaxExpense], true) ? 1 : -1;
    }

    public function isAsset(): bool
    {
        return in_array($this, [self::NonCurrentAsset, self::CurrentAsset], true);
    }

    public function isLiability(): bool
    {
        return in_array($this, [self::NonCurrentLiability, self::CurrentLiability], true);
    }
}
