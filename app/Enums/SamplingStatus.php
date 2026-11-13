<?php

namespace App\Enums;

enum SamplingStatus: string
{
    use EnumHelpers;

    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Selected = 'selected';
    case Evaluated = 'evaluated';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Awaiting approval',
            self::Approved => 'Approved — ready to select',
            self::Rejected => 'Returned',
            self::Selected => 'Selected — testing',
            self::Evaluated => 'Evaluated',
        };
    }
}
