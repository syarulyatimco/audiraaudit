<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One row of a programme grid. "Done by / Date" is the preparer sign-off, bound to contentHash(). */
class ProgrammeRow extends Model
{
    use BelongsToFirm;

    /** Cells a person types into; everything else is set by sign-off actions. */
    public const EDITABLE = ['ref', 'procedure', 'assertions', 'risk', 'wp_ref', 'conclusion'];

    protected $fillable = ['sheet_id', 'position', 'ref', 'procedure', 'assertions', 'risk', 'wp_ref', 'conclusion', 'extra', 'isa_refs', 'guidance', 'procedure_id', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['extra' => 'array', 'done_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (ProgrammeRow $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(ProgrammeSheet::class, 'sheet_id');
    }

    /** The risk-response procedure (Fieldwork › Risk responses) this step is carried out through. */
    public function linkedProcedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class, 'procedure_id');
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function contentHash(): string
    {
        $extra = $this->extra ?? [];
        ksort($extra);

        return hash('sha256', json_encode([
            $this->ref, $this->procedure, $this->assertions, $this->risk, $this->wp_ref, $this->conclusion, $extra,
            ...($this->procedure_id ? [['procedure_id' => (int) $this->procedure_id]] : []),
        ], JSON_UNESCAPED_UNICODE));
    }

    public function isDone(): bool
    {
        return $this->done_by !== null;
    }

    public function isReviewed(): bool
    {
        return $this->reviewed_by !== null;
    }

    /** True when the row changed after it was signed (should never persist: edits clear sign-offs). */
    public function signatureBroken(): bool
    {
        return $this->signed_hash !== null && ! hash_equals($this->signed_hash, $this->contentHash());
    }

    /** The token pasted into other papers to link back to this row. */
    public function linkToken(): string
    {
        return '[[step:'.$this->uuid.']]';
    }
}
