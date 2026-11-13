<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Fields extracted from a document version by the Document Intelligence agent and accepted by a person. */
class DocumentExtraction extends Model
{
    use BelongsToFirm;

    protected $fillable = ['document_id', 'document_version_id', 'ai_run_id', 'document_type', 'summary', 'fields', 'accepted_by'];

    protected function casts(): array
    {
        return ['fields' => 'array'];
    }

    public function acceptor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }
}
