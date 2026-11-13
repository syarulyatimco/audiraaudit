<?php

namespace App\Models;

use App\Enums\EngagementRole;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngagementMember extends Model
{
    use BelongsToFirm;

    protected $fillable = ['engagement_id', 'user_id', 'role', 'added_by'];

    protected function casts(): array
    {
        return ['role' => EngagementRole::class];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
