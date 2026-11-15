<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A frozen submission of management's financial statements (DB trigger: content immutable). */
class ClientFsVersion extends Model
{
    use BelongsToFirm;

    protected $fillable = ['statement_id', 'version', 'content', 'content_sha256', 'submitted_by', 'submitted_at', 'directors_approved', 'approved_by_names', 'approved_on'];

    protected function casts(): array
    {
        return ['content' => 'array', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_on' => 'date', 'directors_approved' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (ClientFsVersion $v) => $v->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(ClientFinancialStatement::class, 'statement_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
