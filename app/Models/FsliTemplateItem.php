<?php

namespace App\Models;

use App\Enums\FsliClassification;
use Illuminate\Database\Eloquent\Model;

class FsliTemplateItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['template_id', 'code', 'name', 'classification', 'sort_order'];

    protected function casts(): array
    {
        return ['classification' => FsliClassification::class];
    }
}
