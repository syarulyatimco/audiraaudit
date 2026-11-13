<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;

/** Immutable once imported (DB trigger). */
class TbLine extends Model
{
    use BelongsToFirm;

    public $timestamps = false;

    protected $fillable = ['tb_import_id', 'row_no', 'account_code', 'account_name', 'debit_sen', 'credit_sen', 'balance_sen'];

    protected function casts(): array
    {
        return ['debit_sen' => 'integer', 'credit_sen' => 'integer', 'balance_sen' => 'integer'];
    }
}
