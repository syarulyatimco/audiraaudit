<?php

namespace App\Enums;

enum Assertion: string
{
    use EnumHelpers;

    case Occurrence = 'occurrence';
    case Completeness = 'completeness';
    case Accuracy = 'accuracy';
    case CutOff = 'cut_off';
    case Classification = 'classification';
    case Existence = 'existence';
    case RightsObligations = 'rights_obligations';
    case ValuationAllocation = 'valuation_allocation';
    case Presentation = 'presentation';

    public function label(): string
    {
        return match ($this) {
            self::Occurrence => 'Occurrence',
            self::Completeness => 'Completeness',
            self::Accuracy => 'Accuracy',
            self::CutOff => 'Cut-off',
            self::Classification => 'Classification',
            self::Existence => 'Existence',
            self::RightsObligations => 'Rights and obligations',
            self::ValuationAllocation => 'Valuation and allocation',
            self::Presentation => 'Presentation and disclosure',
        };
    }
}
