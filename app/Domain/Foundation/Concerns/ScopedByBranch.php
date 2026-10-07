<?php

namespace App\Domain\Foundation\Concerns;

/**
 * Applied to branch-sensitive models (Rule 5, decision D6).
 * Scope implementation lives in BranchScope.
 */
trait ScopedByBranch
{
    public static function bootScopedByBranch(): void
    {
        static::addGlobalScope(new BranchScope);
    }
}
