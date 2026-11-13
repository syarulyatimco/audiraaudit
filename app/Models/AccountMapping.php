<?php

namespace App\Models;

use App\Enums\MappingSource;
use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountMapping extends Model
{
    use BelongsToFirm;

    protected $fillable = ['engagement_id', 'account_code', 'engagement_fsli_id', 'source', 'mapped_by'];

    protected function casts(): array
    {
        return ['source' => MappingSource::class];
    }

    public function fsli(): BelongsTo
    {
        return $this->belongsTo(EngagementFsli::class, 'engagement_fsli_id');
    }

    public function mapper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mapped_by');
    }
}
