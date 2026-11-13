<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;

/** Append-only metering (DB trigger). */
class AiUsage extends Model
{
    use BelongsToFirm;

    public const UPDATED_AT = null;

    protected $table = 'ai_usage';

    protected $fillable = ['firm_id', 'engagement_id', 'ai_run_id', 'provider', 'model', 'input_tokens', 'output_tokens', 'cost_micros', 'credits'];
}
