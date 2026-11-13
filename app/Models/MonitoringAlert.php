<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringAlert extends Model
{
    use BelongsToFirm;

    protected $fillable = ['engagement_id', 'kind', 'severity', 'message', 'data', 'first_seen_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'notified_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('resolved_at');
    }
}
