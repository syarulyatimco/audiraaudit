<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;

/** Append-only execution log (DB trigger). */
class AiRunStep extends Model
{
    use BelongsToFirm;

    public const UPDATED_AT = null;

    protected $fillable = ['ai_run_id', 'seq', 'type', 'name', 'payload', 'input_tokens', 'output_tokens', 'duration_ms'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }
}
