<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Overall audit strategy (one per engagement); documented and signed off through working paper A1. */
class AuditStrategy extends Model
{
    use BelongsToFirm;

    protected $fillable = ['engagement_id', 'sections', 'controls_approach', 'planning_meeting_on', 'fieldwork_start_on', 'fieldwork_end_on', 'report_target_on', 'updated_by'];

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'planning_meeting_on' => 'date',
            'fieldwork_start_on' => 'date',
            'fieldwork_end_on' => 'date',
            'report_target_on' => 'date',
        ];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
