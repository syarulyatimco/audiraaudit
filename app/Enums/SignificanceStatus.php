<?php

namespace App\Enums;

enum SignificanceStatus: string
{
    use EnumHelpers;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting partner approval',
            self::Approved => 'Approved by partner',
            self::Rejected => 'Returned by partner',
        };
    }
}
