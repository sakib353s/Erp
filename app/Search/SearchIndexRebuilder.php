<?php

namespace App\Search;

use App\Domain\Documents\Document;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Role;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Safe, full rebuild of the search index from source tables only
 * (row 15-28). Never invents rows; deletes and recreates the
 * company's index in one transaction. Returns per-entity counts.
 */
class SearchIndexRebuilder
{
    /**
     * @return array<string, int> entity_type => rows written
     */
    public function rebuild(?int $companyId = null): array
    {
        $companyId ??= Company::current()?->id;

        if ($companyId === null) {
            return [];
        }

        return DB::transaction(function () use ($companyId) {
            SearchIndex::query()->where('company_id', $companyId)->delete();

            $counts = [];

            $counts['user'] = $this->indexUsers($companyId);
            $counts['branch'] = $this->indexBranches($companyId);
            $counts['warehouse'] = $this->indexWarehouses($companyId);
            $counts['role'] = $this->indexRoles($companyId);
            $counts['document'] = $this->indexDocuments($companyId);

            return array_filter($counts, fn ($n) => $n > 0);
        });
    }

    protected function indexUsers(int $companyId): int
    {
        $count = 0;

        User::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->chunkById(200, function ($users) use (&$count) {
                foreach ($users as $user) {
                    SearchIndex::updateOrCreate(
                        ['entity_type' => 'user', 'entity_id' => $user->id],
                        [
                            'company_id' => $user->company_id,
                            'branch_id' => $user->default_branch_id,
                            'permission_key' => 'users.view',
                            'title' => $user->name,
                            'subtitle' => $user->email,
                            'excerpt' => $user->phone,
                            'url' => '/app/users/'.$user->id,
                            'normalized' => mb_strtolower(
                                implode(' ', array_filter([
                                    $user->name,
                                    $user->email,
                                    $user->phone,
                                ]))
                            ),
                        ],
                    );
                    $count++;
                }
            });

        return $count;
    }

    protected function indexBranches(int $companyId): int
    {
        $count = 0;

        Branch::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->chunkById(200, function ($branches) use (&$count) {
                foreach ($branches as $branch) {
                    SearchIndex::updateOrCreate(
                        ['entity_type' => 'branch', 'entity_id' => $branch->id],
                        [
                            'company_id' => $branch->company_id,
                            'branch_id' => $branch->id,
                            'permission_key' => 'branches.view',
                            'title' => $branch->name,
                            'subtitle' => $branch->code,
                            'excerpt' => collect([
                                $branch->address_line1,
                                $branch->district,
                            ])->filter()->implode(', '),
                            'url' => '/app/branches/'.$branch->id,
                            'normalized' => mb_strtolower(
                                implode(' ', array_filter([
                                    $branch->name,
                                    $branch->code,
                                    $branch->address_line1,
                                    $branch->district,
                                ]))
                            ),
                        ],
                    );
                    $count++;
                }
            });

        return $count;
    }

    protected function indexWarehouses(int $companyId): int
    {
        $count = 0;

        Warehouse::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->chunkById(200, function ($warehouses) use (&$count) {
                foreach ($warehouses as $warehouse) {
                    SearchIndex::updateOrCreate(
                        ['entity_type' => 'warehouse', 'entity_id' => $warehouse->id],
                        [
                            'company_id' => $warehouse->company_id,
                            'branch_id' => $warehouse->branch_id,
                            'permission_key' => 'warehouses.view',
                            'title' => $warehouse->name,
                            'subtitle' => $warehouse->code,
                            'excerpt' => $warehouse->address,
                            'url' => '/app/warehouses/'.$warehouse->id.'/edit',
                            'normalized' => mb_strtolower(
                                implode(' ', array_filter([
                                    $warehouse->name,
                                    $warehouse->code,
                                    $warehouse->address,
                                ]))
                            ),
                        ],
                    );
                    $count++;
                }
            });

        return $count;
    }

    protected function indexRoles(int $companyId): int
    {
        $count = 0;

        Role::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->chunkById(200, function ($roles) use (&$count) {
                foreach ($roles as $role) {
                    SearchIndex::updateOrCreate(
                        ['entity_type' => 'role', 'entity_id' => $role->id],
                        [
                            'company_id' => $role->company_id,
                            'branch_id' => null,
                            'permission_key' => 'roles.view',
                            'title' => $role->name,
                            'subtitle' => $role->slug,
                            'excerpt' => $role->description,
                            'url' => '/app/roles/'.$role->id,
                            'normalized' => mb_strtolower(
                                implode(' ', array_filter([
                                    $role->name,
                                    $role->slug,
                                    $role->description,
                                ]))
                            ),
                        ],
                    );
                    $count++;
                }
            });

        return $count;
    }

    protected function indexDocuments(int $companyId): int
    {
        $count = 0;

        Document::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->chunkById(200, function ($documents) use (&$count) {
                foreach ($documents as $document) {
                    SearchIndex::updateOrCreate(
                        ['entity_type' => 'document', 'entity_id' => $document->id],
                        [
                            'company_id' => $document->company_id,
                            'branch_id' => $document->branch_id,
                            'permission_key' => 'documents.view',
                            'title' => $document->original_name,
                            'subtitle' => $document->purpose ?: 'attachment',
                            'excerpt' => $document->mime_type,
                            'url' => '/app/documents',
                            'normalized' => mb_strtolower(
                                implode(' ', array_filter([
                                    $document->original_name,
                                    $document->purpose,
                                    $document->mime_type,
                                ]))
                            ),
                        ],
                    );
                    $count++;
                }
            });

        return $count;
    }
}
