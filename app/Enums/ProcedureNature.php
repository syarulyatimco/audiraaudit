<?php

namespace App\Enums;

enum ProcedureNature: string
{
    use EnumHelpers;

    case TestOfDetails = 'test_of_details';
    case SubstantiveAnalytical = 'substantive_analytical';
    case TestOfControls = 'test_of_controls';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::TestOfDetails => 'Test of details',
            self::SubstantiveAnalytical => 'Substantive analytical procedure',
            self::TestOfControls => 'Test of controls',
            self::Other => 'Other procedure',
        };
    }
}
