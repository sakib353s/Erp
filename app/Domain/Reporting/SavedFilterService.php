<?php

namespace App\Domain\Reporting;

use App\Domain\Foundation\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Named filter payloads for a saved report definition. Payload keys are
 * validated against the source whitelist on save AND re-checked (dropped)
 * by CustomReportBuilder on every run.
 */
class SavedFilterService
{
    public function __construct(protected CustomReportBuilder $builder) {}

    public function save(User $user, ReportDefinition $definition, string $name, array $payload): SavedFilter
    {
        $allowed = $this->builder->filterKeys($definition->source);

        foreach (array_keys($payload) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw ValidationException::withMessages(['payload' => "Unknown filter: {$key}."]);
            }
        }

        $row = SavedFilter::query()
            ->where('company_id', $user->company_id)
            ->where('report_definition_id', $definition->id)
            ->where('name', $name)
            ->first();

        $row ??= new SavedFilter([
            'company_id' => $definition->company_id,
            'report_definition_id' => $definition->id,
        ]);

        $row->fill([
            'name' => $name,
            'payload' => $payload,
            'created_by' => $user->id,
        ])->save();

        return $row;
    }

    /** @return Collection<int, SavedFilter> */
    public function list(User $user, ReportDefinition $definition): Collection
    {
        return SavedFilter::query()
            ->where('company_id', $user->company_id)
            ->where('report_definition_id', $definition->id)
            ->orderBy('name')
            ->get();
    }

    public function delete(User $user, int $savedFilterId): void
    {
        SavedFilter::query()
            ->where('company_id', $user->company_id)
            ->findOrFail($savedFilterId)
            ->delete();
    }
}
