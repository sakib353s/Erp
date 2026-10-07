<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingsRequest;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Settings screens driven by config('erp.settings.groups') metadata:
 * the form is built from the group definition, values persist in the
 * `settings` table (branch-aware, encrypted for sensitive keys,
 * versioned in setting_history, audited). Unknown groups → 404.
 */
class SettingController extends Controller
{
    public function __construct(
        protected SettingService $settings,
        protected AuditRecorder $audit,
    ) {}

    public function show(Request $request, string $group): View
    {
        $definition = $this->definition($group);

        return view('settings.show', [
            'group' => $group,
            'definition' => $definition,
            'values' => $this->settings->group($group),
            'updated_at' => null,
        ]);
    }

    public function update(UpdateSettingsRequest $request, string $group): RedirectResponse
    {
        $this->definition($group);

        $result = $this->settings->setGroup(
            $group,
            $request->validated('settings'),
            null,
            $request->user(),
        );

        $changed = array_keys(array_filter($result, fn ($changed) => $changed));

        return back()->with(
            'status',
            $changed === []
                ? 'No changes to save.'
                : 'Saved '.count($changed).'.',
        );
    }

    /** @return array<string, array<string, mixed>> */
    protected function definition(string $group): array
    {
        $definition = config("erp.settings.groups.{$group}");

        if (! is_array($definition) || ! isset($definition['fields'])) {
            abort(404, 'Unknown settings group.');
        }

        return $definition;
    }
}
