<?php

namespace App\Enums;

enum SamplingMethod: string
{
    use EnumHelpers;

    case Mus = 'mus';
    case Random = 'random';
    case Systematic = 'systematic';
    case Stratified = 'stratified';
    case Haphazard = 'haphazard';
    case Judgemental = 'judgemental';

    public function label(): string
    {
        return match ($this) {
            self::Mus => 'Monetary unit sampling',
            self::Random => 'Random selection',
            self::Systematic => 'Systematic selection',
            self::Stratified => 'Stratified random selection',
            self::Haphazard => 'Haphazard selection',
            self::Judgemental => 'Judgemental (risk-based) selection',
        };
    }

    /** Methods where the auditor picks the items; the system only records them. */
    public function isManual(): bool
    {
        return in_array($this, [self::Haphazard, self::Judgemental], true);
    }

    /** Methods that can only ever be non-statistical. */
    public function alwaysNonStatistical(): bool
    {
        return $this->isManual();
    }
}
