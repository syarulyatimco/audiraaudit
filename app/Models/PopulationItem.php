<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;

class PopulationItem extends Model
{
    use BelongsToFirm;

    public $timestamps = false;

    protected $fillable = ['sampling_plan_id', 'seq', 'item_ref', 'description', 'amount_sen', 'item_date'];

    protected function casts(): array
    {
        return ['amount_sen' => 'integer', 'item_date' => 'date'];
    }
}
