<?php

namespace App\Enums;

/** The three programme stages every FSLI and engagement programme falls into. */
enum ProgrammeStage: string
{
    use EnumHelpers;

    case Planning = 'planning';
    case Controls = 'controls';
    case Substantive = 'substantive';

    public function label(): string
    {
        return match ($this) {
            self::Planning => 'Audit planning',
            self::Controls => 'Controls testing',
            self::Substantive => 'Substantive testing',
        };
    }

    /** Suffix used in sheet references, e.g. TR-S, REV-C. */
    public function suffix(): string
    {
        return match ($this) {
            self::Planning => 'P',
            self::Controls => 'C',
            self::Substantive => 'S',
        };
    }
}
