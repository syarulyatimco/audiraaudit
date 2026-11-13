<?php

namespace App\Enums;

enum PbcCategory: string
{
    use EnumHelpers;

    case Financial = 'financial';
    case Legal = 'legal';
    case Tax = 'tax';
    case Banking = 'banking';
    case Payroll = 'payroll';
    case FixedAssets = 'fixed_assets';
    case Receivables = 'receivables';
    case Payables = 'payables';
    case Inventory = 'inventory';
    case Governance = 'governance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Financial => 'Financial records',
            self::Legal => 'Legal and secretarial',
            self::Tax => 'Tax',
            self::Banking => 'Banking',
            self::Payroll => 'Payroll',
            self::FixedAssets => 'Fixed assets',
            self::Receivables => 'Receivables',
            self::Payables => 'Payables',
            self::Inventory => 'Inventory',
            self::Governance => 'Governance and minutes',
            self::Other => 'Other',
        };
    }
}
