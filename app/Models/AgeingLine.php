<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;

class AgeingLine extends Model
{
    use BelongsToFirm;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['bucket_sen' => 'array'];
    }
}
