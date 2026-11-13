<?php

namespace App\Models;

use App\Enums\FsliClassification;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EngagementFsli extends Model
{
    use BelongsToFirm;

    protected $fillable = ['engagement_id', 'code', 'name', 'classification', 'sort_order', 'is_custom', 'working_paper_id'];

    protected function casts(): array
    {
        return ['classification' => FsliClassification::class, 'is_custom' => 'boolean'];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(AccountMapping::class);
    }

    public function workingPaper(): BelongsTo
    {
        return $this->belongsTo(WorkingPaper::class);
    }
}
