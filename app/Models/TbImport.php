<?php

namespace App\Models;

use App\Enums\TbImportStatus;
use App\Enums\TbPeriod;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TbImport extends Model
{
    use BelongsToFirm;

    protected $fillable = ['engagement_id', 'period', 'document_id', 'raw_rows', 'imported_by'];

    protected $hidden = ['raw_rows'];

    protected function casts(): array
    {
        return [
            'period' => TbPeriod::class,
            'status' => TbImportStatus::class,
            'raw_rows' => 'array',
            'column_map' => 'array',
            'warnings' => 'array',
            'activated_at' => 'datetime',
            'total_debit_sen' => 'integer',
            'total_credit_sen' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TbImport $i) {
            $i->uuid ??= (string) Str::uuid();
            $i->status ??= TbImportStatus::Pending;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TbLine::class)->orderBy('row_no');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
