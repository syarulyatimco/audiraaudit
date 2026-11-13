<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;

class AdjustmentLine extends Model
{
    use BelongsToFirm;

    public $timestamps = false;

    protected $fillable = ['adjustment_id', 'account_code', 'description', 'debit_sen', 'credit_sen', 'sort_order'];

    protected function casts(): array
    {
        return ['debit_sen' => 'integer', 'credit_sen' => 'integer'];
    }
}
