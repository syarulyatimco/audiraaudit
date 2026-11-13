<?php

namespace App\Support;

use App\Models\Engagement;
use Illuminate\Validation\ValidationException;

final class EngagementGuard
{
    /** Signed-off / archived engagement files are read-only. */
    public static function assertEditable(Engagement $engagement): void
    {
        if ($engagement->status->isLocked()) {
            throw ValidationException::withMessages(['engagement' => 'This engagement is signed off and its file is locked.']);
        }
    }
}
