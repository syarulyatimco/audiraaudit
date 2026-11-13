<?php

namespace App\Enums;

enum WorkingPaperSection: string
{
    use EnumHelpers;

    case Permanent = 'permanent';
    case Planning = 'planning';
    case EntityUnderstanding = 'entity_understanding';
    case RiskAssessment = 'risk_assessment';
    case Materiality = 'materiality';
    case Fieldwork = 'fieldwork';
    case Completion = 'completion';
    case Reporting = 'reporting';

    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'Permanent file',
            self::Planning => 'Planning',
            self::EntityUnderstanding => 'Understanding the entity',
            self::RiskAssessment => 'Risk assessment',
            self::Materiality => 'Materiality',
            self::Fieldwork => 'Fieldwork',
            self::Completion => 'Completion',
            self::Reporting => 'Reporting',
        };
    }

    /** Conventional index letter used to suggest working-paper references. */
    public function prefix(): string
    {
        return match ($this) {
            self::Permanent => 'P',
            self::Planning => 'A',
            self::EntityUnderstanding => 'B',
            self::RiskAssessment => 'C',
            self::Materiality => 'D',
            self::Fieldwork => 'F',
            self::Completion => 'X',
            self::Reporting => 'Z',
        };
    }
}
