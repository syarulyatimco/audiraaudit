<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lower materiality for a particular class of transactions, account balance or disclosure. */
class SpecificMateriality extends Model
{
    use BelongsToFirm;

    protected $fillable = ['materiality_assessment_id', 'engagement_fsli_id', 'area', 'amount_sen', 'rationale'];

    protected function casts(): array
    {
        return ['amount_sen' => 'integer'];
    }

    public function fsli(): BelongsTo
    {
        return $this->belongsTo(EngagementFsli::class, 'engagement_fsli_id');
    }
}
