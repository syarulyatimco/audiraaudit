<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One imported general-ledger posting (immutable: DB trigger). */
class GlLine extends Model
{
    use BelongsToFirm;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['posted_on' => 'date', 'is_opening' => 'boolean'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(GlImport::class, 'gl_import_id');
    }
}
