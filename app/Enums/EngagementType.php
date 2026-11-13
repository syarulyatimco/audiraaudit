<?php

namespace App\Enums;

enum EngagementType: string
{
    use EnumHelpers;

    case StatutoryAudit = 'statutory_audit';
    case SpecialPurpose = 'special_purpose';
    case Review = 'review';
    case AgreedUponProcedures = 'agreed_upon_procedures';

    public function label(): string
    {
        return match ($this) {
            self::StatutoryAudit => 'Statutory audit',
            self::SpecialPurpose => 'Special purpose audit',
            self::Review => 'Review engagement',
            self::AgreedUponProcedures => 'Agreed-upon procedures',
        };
    }
}
