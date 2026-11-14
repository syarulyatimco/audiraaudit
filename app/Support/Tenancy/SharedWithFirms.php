<?php

namespace App\Support\Tenancy;

use App\Models\Firm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For content AUDIRA publishes to every firm (firm_id NULL) alongside firm-owned copies.
 * Reads see platform rows plus the current firm's rows; writes always carry the current firm,
 * except in system mode (platform seeding). RLS enforces the same rule in PostgreSQL.
 *
 * @mixin Model
 */
trait SharedWithFirms
{
    public static function bootSharedWithFirms(): void
    {
        static::addGlobalScope('shared_with_firms', function (Builder $q) {
            $context = app(TenantContext::class);
            if ($context->isSystem()) {
                return;
            }
            $column = $q->getModel()->qualifyColumn('firm_id');
            if (! $context->hasTenant()) {
                $q->whereNull($column);

                return;
            }
            $q->where(fn ($w) => $w->whereNull($column)->orWhere($column, $context->id()));
        });

        static::creating(function (Model $model) {
            $context = app(TenantContext::class);
            if ($context->isSystem() && ! $context->hasTenant()) {
                return; // platform template row
            }
            if (! $context->hasTenant()) {
                throw new TenantViolation('Cannot create '.class_basename($model).' without a tenant.');
            }
            if ($model->firm_id !== null && (int) $model->firm_id !== $context->id() && ! $context->isSystem()) {
                throw new TenantViolation('Cross-tenant write blocked for '.class_basename($model).'.');
            }
            $model->firm_id ??= $context->id();
        });

        $guard = function (Model $model) {
            $context = app(TenantContext::class);
            if ($model->getOriginal('firm_id') === null && ! $context->isSystem()) {
                throw new TenantViolation('The AUDIRA methodology template is read-only; make a firm copy to edit it.');
            }
            if ($model->isDirty('firm_id') && $model->exists) {
                throw new TenantViolation('firm_id is immutable on '.class_basename($model).'.');
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

    public function isPlatform(): bool
    {
        return $this->firm_id === null;
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }
}
