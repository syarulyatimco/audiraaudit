<?php

namespace App\Models;

use App\Enums\AuditOpinion;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;

/** A firm's approved auditor's-report wording for one opinion type, with placeholders. */
class ReportTemplate extends Model
{
    use BelongsToFirm;

    protected $fillable = ['opinion', 'body', 'updated_by'];

    protected function casts(): array
    {
        return ['opinion' => AuditOpinion::class];
    }
}
