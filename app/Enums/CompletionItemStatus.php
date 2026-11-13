<?php

namespace App\Enums;

enum CompletionItemStatus: string
{
    use EnumHelpers;

    case Open = 'open';
    case Done = 'done';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Done => 'Done',
            self::NotApplicable => 'Not applicable',
        };
    }
}
