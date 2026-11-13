<?php

namespace App\Enums;

enum SamplingApproach: string
{
    use EnumHelpers;

    case Statistical = 'statistical';
    case NonStatistical = 'non_statistical';

    public function label(): string
    {
        return match ($this) {
            self::Statistical => 'Statistical',
            self::NonStatistical => 'Non-statistical',
        };
    }
}
