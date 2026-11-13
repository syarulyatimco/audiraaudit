<?php

namespace App\Enums;

enum InherentRiskFactor: string
{
    use EnumHelpers;

    case Complexity = 'complexity';
    case Subjectivity = 'subjectivity';
    case Change = 'change';
    case Uncertainty = 'uncertainty';
    case BiasOrFraud = 'bias_or_fraud';

    public function label(): string
    {
        return match ($this) {
            self::Complexity => 'Complexity',
            self::Subjectivity => 'Subjectivity',
            self::Change => 'Change',
            self::Uncertainty => 'Uncertainty',
            self::BiasOrFraud => 'Susceptibility to misstatement due to management bias or fraud',
        };
    }
}
