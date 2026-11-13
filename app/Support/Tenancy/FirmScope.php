<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class FirmScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isSystem()) {
            return;
        }

        if (! $context->hasTenant()) {
            // Fail closed: never return cross-tenant data when no tenant is known.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('firm_id'), $context->id());
    }
}
