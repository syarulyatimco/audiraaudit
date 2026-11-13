<?php

namespace App\Enums;

enum AuditOpinion: string
{
    use EnumHelpers;

    case Unmodified = 'unmodified';
    case Qualified = 'qualified';
    case Adverse = 'adverse';
    case Disclaimer = 'disclaimer';

    public function label(): string
    {
        return match ($this) {
            self::Unmodified => 'Unmodified opinion',
            self::Qualified => 'Qualified opinion',
            self::Adverse => 'Adverse opinion',
            self::Disclaimer => 'Disclaimer of opinion',
        };
    }
}
