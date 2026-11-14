<?php

namespace App\Models;

use App\Support\Tenancy\SharedWithFirms;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MethodologyStep extends Model
{
    use SharedWithFirms;

    protected $fillable = ['programme_id', 'position', 'ref', 'procedure', 'assertions', 'isa_refs', 'guidance'];

    protected static function booted(): void
    {
        static::creating(fn (MethodologyStep $s) => $s->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(MethodologyProgramme::class, 'programme_id');
    }
}
