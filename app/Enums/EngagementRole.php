<?php

namespace App\Enums;

enum EngagementRole: string
{
    use EnumHelpers;

    case EngagementPartner = 'engagement_partner';
    case EqrReviewer = 'eqr_reviewer';
    case Manager = 'manager';
    case Senior = 'senior';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::EngagementPartner => 'Engagement partner',
            self::EqrReviewer => 'EQR reviewer',
            self::Manager => 'Manager',
            self::Senior => 'Senior',
            self::Staff => 'Staff',
        };
    }
}
