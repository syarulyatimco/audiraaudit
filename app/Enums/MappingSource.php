<?php

namespace App\Enums;

enum MappingSource: string
{
    use EnumHelpers;

    case Manual = 'manual';
    case CarriedForward = 'carried_forward';
    case Rule = 'rule';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::CarriedForward => 'Carried forward',
            self::Rule => 'Rule suggestion',
            self::Ai => 'AI suggestion',
        };
    }
}
