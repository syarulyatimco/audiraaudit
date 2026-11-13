<?php

namespace App\Enums;

enum ProcedureTiming: string
{
    use EnumHelpers;

    case Interim = 'interim';
    case Final = 'final';
    case RollForward = 'roll_forward';

    public function label(): string
    {
        return match ($this) {
            self::Interim => 'Interim',
            self::Final => 'Final',
            self::RollForward => 'Roll-forward',
        };
    }
}
