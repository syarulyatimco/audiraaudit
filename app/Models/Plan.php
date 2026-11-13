<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'description', 'price_per_user_sen', 'currency', 'min_paid_users', 'max_users',
        'ai_credits_per_user', 'features', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'is_active' => 'boolean',
            'price_per_user_sen' => 'integer',
            'min_paid_users' => 'integer',
            'max_users' => 'integer',
            'ai_credits_per_user' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function priceLabel(): string
    {
        return money_label($this->price_per_user_sen, $this->currency).' / user / month';
    }
}
