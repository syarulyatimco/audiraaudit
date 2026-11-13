<?php

namespace App\Support\Tenancy;

use App\Models\Firm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applies the tenant global scope and guarantees firm_id integrity on write.
 *
 * @mixin Model
 */
trait BelongsToFirm
{
    public static function bootBelongsToFirm(): void
    {
        static::addGlobalScope(new FirmScope);

        static::creating(function (Model $model) {
            $context = app(TenantContext::class);

            if (empty($model->firm_id)) {
                if (! $context->hasTenant() && $context->isSystem() && static::allowsPlatformRows()) {
                    return; // platform-level row (e.g. superadmin audit-trail entries)
                }
                if (! $context->hasTenant()) {
                    throw new TenantViolation('Cannot create '.class_basename($model).' without a tenant.');
                }
                $model->firm_id = $context->id();

                return;
            }

            if (! $context->isSystem() && (int) $model->firm_id !== $context->id()) {
                throw new TenantViolation('Cross-tenant write blocked for '.class_basename($model).'.');
            }
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('firm_id')) {
                throw new TenantViolation('firm_id is immutable on '.class_basename($model).'.');
            }
        });
    }

    /** Override to allow rows with a NULL firm_id (written only in system mode). */
    public static function allowsPlatformRows(): bool
    {
        return false;
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }
}
