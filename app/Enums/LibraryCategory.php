<?php

namespace App\Enums;

enum LibraryCategory: string
{
    use EnumHelpers;

    case Reporting = 'reporting';
    case Auditing = 'auditing';
    case Quality = 'quality';
    case Legislation = 'legislation';
    case Regulatory = 'regulatory';
    case Methodology = 'methodology';

    public function label(): string
    {
        return match ($this) {
            self::Reporting => 'Financial reporting',
            self::Auditing => 'Auditing standards',
            self::Quality => 'Quality management',
            self::Legislation => 'Legislation',
            self::Regulatory => 'Regulatory guidance',
            self::Methodology => 'Firm methodology',
        };
    }
}
