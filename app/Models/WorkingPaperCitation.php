<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkingPaperCitation extends Model
{
    use BelongsToFirm;

    public const UPDATED_AT = null;

    protected $fillable = ['working_paper_id', 'library_paragraph_id', 'note', 'added_by'];

    public function paragraph(): BelongsTo
    {
        return $this->belongsTo(LibraryParagraph::class, 'library_paragraph_id');
    }

    public function workingPaper(): BelongsTo
    {
        return $this->belongsTo(WorkingPaper::class);
    }
}
