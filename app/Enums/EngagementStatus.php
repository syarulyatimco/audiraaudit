<?php

namespace App\Enums;

enum EngagementStatus: string
{
    use EnumHelpers;

    case Planning = 'planning';
    case Fieldwork = 'fieldwork';
    case Completion = 'completion';
    case Reporting = 'reporting';
    case SignedOff = 'signed_off';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Planning => 'Planning',
            self::Fieldwork => 'Fieldwork',
            self::Completion => 'Completion',
            self::Reporting => 'Reporting',
            self::SignedOff => 'Signed off',
            self::Archived => 'Archived',
        };
    }

    /** Ordered working stages a team can move between manually. */
    public static function workingStages(): array
    {
        return [self::Planning, self::Fieldwork, self::Completion, self::Reporting];
    }

    public function isLocked(): bool
    {
        return in_array($this, [self::SignedOff, self::Archived], true);
    }

    /**
     * Manual transitions allowed in the current phase. Moving to "signed_off" requires the
     * audit report sign-off approval (Phase 9) and is therefore never a manual transition.
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target || $this->isLocked()) {
            return false;
        }

        return in_array($target, self::workingStages(), true);
    }
}
