<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sign-offs are never deleted: when content changes they are invalidated and kept as history. */
class WpSignoff extends Model
{
    use BelongsToFirm;

    public $timestamps = false;

    protected $fillable = ['working_paper_id', 'user_id', 'role', 'content_hash', 'signed_at'];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime', 'invalidated_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->invalidated_at === null;
    }
}
