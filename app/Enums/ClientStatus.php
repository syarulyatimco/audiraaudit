<?php

namespace App\Enums;

enum ClientStatus: string
{
    use EnumHelpers;

    case Prospect = 'prospect';
    case PendingAcceptance = 'pending_acceptance';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Prospect => 'Prospect',
            self::PendingAcceptance => 'Pending acceptance',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Inactive => 'Inactive',
        };
    }
}
