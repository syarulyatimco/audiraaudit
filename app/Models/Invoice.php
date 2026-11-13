<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A subscription invoice. Amounts are frozen once issued (DB trigger); corrections are void + reissue. */
class Invoice extends Model
{
    use BelongsToFirm;

    protected $fillable = ['subscription_id', 'number', 'period_start', 'period_end', 'currency', 'lines', 'subtotal_sen', 'tax_name', 'tax_rate', 'tax_sen', 'total_sen', 'due_on', 'issued_at', 'gateway'];

    protected function casts(): array
    {
        return [
            'lines' => 'array', 'period_start' => 'date', 'period_end' => 'date', 'due_on' => 'date', 'issued_at' => 'datetime', 'paid_at' => 'datetime',
            'subtotal_sen' => 'integer', 'tax_sen' => 'integer', 'total_sen' => 'integer', 'tax_rate' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Invoice $i) => $i->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'issued' && $this->due_on->isPast() && ! $this->due_on->isToday();
    }
}
