<?php

namespace App\Enums;

enum DocumentCategory: string
{
    use EnumHelpers;

    case Permanent = 'permanent';
    case Planning = 'planning';
    case ClientProvided = 'client_provided';
    case Evidence = 'evidence';
    case Correspondence = 'correspondence';
    case FinancialStatements = 'financial_statements';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'Permanent file',
            self::Planning => 'Planning',
            self::ClientProvided => 'Client-provided (PBC)',
            self::Evidence => 'Audit evidence',
            self::Correspondence => 'Correspondence',
            self::FinancialStatements => 'Financial statements',
            self::Other => 'Other',
        };
    }
}
