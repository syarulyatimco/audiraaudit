<?php

namespace App\Enums;

enum PbcStatus: string
{
    use EnumHelpers;

    case Draft = 'draft';
    case Requested = 'requested';
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Requested => 'Requested',
            self::Submitted => 'Submitted by client',
            self::Accepted => 'Accepted',
            self::Returned => 'Returned to client',
            self::Cancelled => 'Cancelled',
        };
    }
}
