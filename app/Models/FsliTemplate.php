<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Platform-level FSLI structure that engagements copy from. */
class FsliTemplate extends Model
{
    protected $fillable = ['code', 'name', 'description', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(FsliTemplateItem::class, 'template_id')->orderBy('sort_order');
    }
}
