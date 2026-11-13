<?php

namespace App\Enums;

enum EntityType: string
{
    use EnumHelpers;

    case PrivateCompany = 'private_company';
    case PublicCompany = 'public_company';
    case Llp = 'llp';
    case JmbMc = 'jmb_mc';
    case Cooperative = 'cooperative';
    case Society = 'society';
    case SolePropPartnership = 'sole_prop_partnership';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PrivateCompany => 'Private company (Sdn. Bhd.)',
            self::PublicCompany => 'Public company (Bhd.)',
            self::Llp => 'Limited liability partnership',
            self::JmbMc => 'JMB / MC (strata)',
            self::Cooperative => 'Cooperative (SKM)',
            self::Society => 'Society / association',
            self::SolePropPartnership => 'Sole proprietorship / partnership',
            self::Other => 'Other',
        };
    }
}
