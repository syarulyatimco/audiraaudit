<?php

namespace App\Enums;

enum MaterialityStatus: string
{
    use EnumHelpers;

    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Awaiting approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Superseded => 'Superseded',
        };
    }
}
