<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JetTestItem extends Model
{
    use BelongsToFirm;

    protected $fillable = ['jet_test_id', 'gl_line_id', 'reasons'];

    protected function casts(): array
    {
        return ['reasons' => 'array', 'tested_at' => 'datetime'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(GlLine::class, 'gl_line_id');
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(JetTest::class, 'jet_test_id');
    }

    public function tester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tested_by');
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'evidence_document_id');
    }
}
