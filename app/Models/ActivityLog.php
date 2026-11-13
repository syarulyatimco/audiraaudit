<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Append-only. Updates/deletes are blocked here and by a database trigger.
 */
class ActivityLog extends Model
{
    use BelongsToFirm;

    public const UPDATED_AT = null;

    protected $fillable = [
        'firm_id', 'user_id', 'actor_type', 'actor_label', 'event', 'subject_type', 'subject_id',
        'description', 'properties', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['properties' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Activity logs are immutable.'));
        static::deleting(fn () => throw new LogicException('Activity logs are immutable.'));
    }

    public static function allowsPlatformRows(): bool
    {
        return true;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
