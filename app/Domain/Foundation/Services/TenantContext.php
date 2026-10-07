<?php

namespace App\Domain\Foundation\Services;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use RuntimeException;

/**
 * Trusted server-side tenant context (decisions D6, Rules 4 & 5).
 *
 * Bound as a container singleton and rebuilt on EVERY request by
 * SetTenantContext from database state — never from client input.
 * Selecting another branch in the UI re-validates against this object;
 * a user can never widen their own scope through the selector.
 */
class TenantContext
{
    protected ?Company $company = null;

    protected ?User $user = null;

    protected ?Branch $branch = null;

    protected ?Warehouse $warehouse = null;

    protected string $portal = 'erp';

    /** null = all branches within the company. */
    protected ?array $accessibleBranchIds = [];

    public function setCompany(?Company $company): void
    {
        $this->company = $company;
    }

    public function setUser(?User $user): void
    {
        $this->user = $user;
        $this->accessibleBranchIds = $user?->accessibleBranchIds();
    }

    public function setBranch(?Branch $branch): void
    {
        if ($branch !== null && $this->user !== null && ! $this->userHasBranchAccess($branch)) {
            throw new RuntimeException('Branch outside the user scope was rejected by TenantContext.');
        }

        $this->branch = $branch;
    }

    public function setWarehouse(?Warehouse $warehouse): void
    {
        if ($warehouse !== null && $this->branch !== null && $warehouse->branch_id !== $this->branch->id) {
            throw new RuntimeException('Warehouse outside the current branch was rejected by TenantContext.');
        }

        $this->warehouse = $warehouse;
    }

    public function setPortal(string $portal): void
    {
        $this->portal = $portal;
    }

    public function hasCompany(): bool
    {
        return $this->company !== null;
    }

    public function hasBranch(): bool
    {
        return $this->branch !== null;
    }

    public function company(): ?Company
    {
        return $this->company;
    }

    public function companyId(): ?int
    {
        return $this->company?->id;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function branch(): ?Branch
    {
        return $this->branch;
    }

    public function branchId(): ?int
    {
        return $this->branch?->id;
    }

    public function warehouse(): ?Warehouse
    {
        return $this->warehouse;
    }

    public function warehouseId(): ?int
    {
        return $this->warehouse?->id;
    }

    public function portal(): string
    {
        return $this->portal;
    }

    /** Branch ids the current user may access; null = every branch. */
    public function accessibleBranchIds(): ?array
    {
        return $this->accessibleBranchIds;
    }

    /** Server-side assertion used before any branch-scoped mutation. */
    public function assertBranchAccess(int $branchId): void
    {
        if ($this->accessibleBranchIds !== null && ! in_array($branchId, $this->accessibleBranchIds, true)) {
            throw new RuntimeException('Cross-branch access blocked by TenantContext.');
        }
    }

    protected function userHasBranchAccess(Branch $branch): bool
    {
        if ($this->user === null) {
            return true; // pre-auth bootstrap (setup) — no user scope yet
        }

        return $this->user->hasBranchAccess($branch->id);
    }
}
