<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SampleItem extends Model
{
    use BelongsToFirm;

    public $timestamps = false;

    protected $fillable = ['sampling_plan_id', 'population_item_id', 'selection_order', 'selection_reason', 'stratum', 'selection_point_sen', 'selection_note'];

    protected function casts(): array
    {
        return ['selection_point_sen' => 'integer', 'audited_amount_sen' => 'integer', 'tested_at' => 'datetime'];
    }

    public function populationItem(): BelongsTo
    {
        return $this->belongsTo(PopulationItem::class);
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'evidence_document_id');
    }

    public function tester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tested_by');
    }

    /** Book minus audited amount (positive = overstatement). Null until tested. */
    public function misstatementSen(): ?int
    {
        if ($this->result === null) {
            return null;
        }

        return $this->result === 'exception' && $this->audited_amount_sen !== null
            ? $this->populationItem->amount_sen - $this->audited_amount_sen : 0;
    }
}
