<?php

namespace App\Domain\Foundation\Concerns;

use App\Domain\Foundation\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Server-side branch scoping (Rule 5, decision D6).
 *
 * Applied to branch-sensitive models. When a TenantContext with a current
 * branch is bound (every web request), queries automatically restrict to:
 *  - rows of the current branch, or
 *  - rows with a NULL branch (company-level), or
 *  - for the branches table itself: only branches the user may access.
 *
 * The scope is defence-in-depth — policies and query services enforce the
 * same rules explicitly; the client can never widen it.
 */
class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->hasCompany()) {
            return; // console/early boot: no user-facing request context
        }

        $table = $model->getTable();

        if ($table === 'branches') {
            $ids = $context->accessibleBranchIds();

            if ($ids !== null) {
                $builder->whereIn($model->qualifyColumn('id'), $ids);
            }

            return;
        }

        if (! $context->hasBranch()) {
            return;
        }

        $column = $model->qualifyColumn('branch_id');

        $builder->where(function (Builder $q) use ($column, $context) {
            $q->whereNull($column)->orWhere($column, $context->branchId());
        });
    }
}
