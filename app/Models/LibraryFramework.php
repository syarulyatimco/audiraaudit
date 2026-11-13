<?php

namespace App\Models;

use App\Enums\LibraryCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Platform-wide (not tenant-owned). Curated by Superadmin. */
class LibraryFramework extends Model
{
    protected $fillable = ['code', 'name', 'category', 'issuer', 'jurisdiction', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['category' => LibraryCategory::class, 'is_active' => 'boolean'];
    }

    public function standards(): HasMany
    {
        return $this->hasMany(LibraryStandard::class, 'framework_id')->orderBy('sort_order')->orderBy('reference');
    }
}
