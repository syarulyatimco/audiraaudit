<?php

namespace App\Models;

use App\Enums\LibraryVersionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LibraryStandard extends Model
{
    protected $fillable = ['framework_id', 'reference', 'title', 'sort_order'];

    public function framework(): BelongsTo
    {
        return $this->belongsTo(LibraryFramework::class, 'framework_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(LibraryVersion::class, 'standard_id')->latest('id');
    }

    public function publishedVersion(): HasOne
    {
        return $this->hasOne(LibraryVersion::class, 'standard_id')->where('status', LibraryVersionStatus::Published->value);
    }

    public function label(): string
    {
        return $this->reference.' — '.$this->title;
    }
}
