<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Settings\Services\InvariantGuard;
use App\Domain\Settings\Services\SettingService;
use App\Http\Requests\UpdateSettingsRequest;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Settings\Setting;
use App\Domain\Settings\Support\GroupRules;
use App\Domain\Foundation\Services\Translator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Settings screens driven by config('erp.settings.groups') metadata:
 * the form is built from the group definition, values persist in the
 * `settings` table (branch-aware, encrypted for sensitive keys,
 * versioned in setting_history, audited).
 *
 * §15 adds three things this controller is responsible for, and they are all
 * about who may move which number:
 *
 *  · an **index** — the module's own landing page, because a settings desk that
 *    only exists as fifteen tabs is not a desk. It lists every group with how
 *    many of its fields are actually set, who set them last, and the key this
 *    group needs;
 *  · a **key per group** — `settings.view` gets you into the module,
 *    `settings.<group>` gets you into that group's numbers, and `settings.update`
 *    is what actually writes. A person who may set the company's document
 *    numbering has no business rewriting the password policy;
 *  · **branch scope** — the settings a single outlet may legitimately decide for
 *    itself, with a screen that shows the company's value beside the branch's,
 *    and the ability to *remove* an override so the branch follows the company
 *    again. Company policy (security, audit, workflow, notifications) is listed
 *    there too — greyed, with the reason, which is more honest than hiding it.
 *
 * Every refusal the guard makes arrives as a validation error on the field the
 * person touched, and every write lands in setting_history and the audit trail.
 */
class SettingController extends Controller
{
    public function __construct(
        protected SettingService $settings,
        protected AuditRecorder $audit,
        protected InvariantGuard $guard,
        protected TenantContext $context,
    ) {}

    /** §15 — the settings desk: every group, what it holds, and who may open it. */
    public function index(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;
        $user = $request->user();

        // Two reads, then the arithmetic in PHP: every company-scope row and
        // every branch override. A settings desk is opened by a handful of
        // people, and one pass over a small table beats fifteen aggregate
        // queries that each walk the history table.
        $companyRows = Setting::query()
            ->where('company_id', $companyId)
            ->where('branch_id', Setting::COMPANY_SCOPE)
            ->with('updatedBy')
            ->get()
            ->groupBy('setting_group');

        $overrides = Setting::query()
            ->where('company_id', $companyId)
            ->where('branch_id', '!=', Setting::COMPANY_SCOPE)
            ->get()
            ->groupBy('setting_group');

        $groups = [];

        foreach ((array) config('erp.settings.groups', []) as $key => $definition) {
            $rows = $companyRows[$key] ?? collect();
            $branchRows = $overrides[$key] ?? collect();
            $last = $rows->sortByDesc('updated_at')->first();
            $permissionKey = $definition['key'] ?? 'settings.update';

            $groups[$key] = [
                'label' => $definition['label'] ?? ucfirst($key),
                'description' => $definition['description'] ?? '',
                'fields' => count($definition['fields'] ?? []),
                'set' => $rows->count(),
                'overrides' => $branchRows->count(),
                'branches' => $branchRows->pluck('branch_id')->unique()->count(),
                'last_changed_at' => $last?->updated_at,
                'last_changed_by' => $last?->updatedBy?->name,
                'permission' => $permissionKey,
                'branch_scoped' => ! $this->guard->isCompanyOnly($key),
                'allowed' => $user->isSuperAdmin() || $user->can($permissionKey),
                'can_update' => $user->isSuperAdmin() || $user->can('settings.update'),
            ];
        }

        return view('settings.index', [
            'groups' => $groups,
            'branchCount' => Branch::query()->count(),
            'floors' => $this->guard->floors(),
        ]);
    }

    public function show(Request $request, string $group): View
    {
        $definition = $this->definition($group);
        $this->authorizeGroup($request->user(), $group, $definition);

        return view('settings.show', [
            'group' => $group,
            'definition' => $definition,
            'values' => $this->settings->group($group),
            'updated_at' => null,
            'branchOverrides' => $this->settings->overrides($group),
            'companyOnly' => $this->guard->isCompanyOnly($group),
            'floors' => $this->guard->floors()[$group] ?? [],
        ]);
    }

    public function update(UpdateSettingsRequest $request, string $group): RedirectResponse
    {
        $definition = $this->definition($group);
        $this->authorizeGroup($request->user(), $group, $definition, write: true);

        $result = $this->settings->setGroup(
            $group,
            $request->validated('settings'),
            null,
            $request->user(),
        );

        // The service reports what it stored and what it refused. Counting
        // refusals as saves is the one thing this screen must never do.
        $status = $result['saved'] === []
            ? 'No changes to save.'
            : 'Saved '.count($result['saved']).' setting(s).';

        // §15-07: the localization group decides the interface language, and the
        // translator memoises its answer for the request. A write flushes it, so
        // “default language” takes effect on the next render rather than on the
        // next deploy.
        if ($group === 'localization') {
            app(Translator::class)->flushLocale();
        }

        $redirect = back()->with('status', $status);

        if ($result['rejected'] !== []) {
            $redirect->with('warning', sprintf(
                '%s refused: %s cannot be changed from a settings screen.',
                count($result['rejected']) === 1 ? 'One setting was' : count($result['rejected']).' settings were',
                implode(', ', $result['rejected']),
            ));
        }

        return $redirect;
    }

    /* ------------------------------------------------------------ branch scope */

    /**
     * §15-03: the branches, and how much each one has decided for itself. This is
     * what the catalogue leaf "Branch Settings" points at — a branch name is not
     * a branch id, and nobody should have to know one to get here.
     */
    public function branches(Request $request): View
    {
        $branches = Branch::query()->orderBy('name')->get();

        $overrides = Setting::query()
            ->where('company_id', (int) $request->user()->company_id)
            ->where('branch_id', '!=', Setting::COMPANY_SCOPE)
            ->get()
            ->groupBy('branch_id');

        $scope = $this->context->accessibleBranchIds();

        return view('settings.branches', [
            'branches' => $branches->map(fn (Branch $branch): array => [
                'branch' => $branch,
                'overrides' => ($overrides[$branch->id] ?? collect())->count(),
                'groups' => ($overrides[$branch->id] ?? collect())->pluck('setting_group')->unique()->count(),
                'last_changed_at' => ($overrides[$branch->id] ?? collect())->max('updated_at'),
                'in_scope' => $scope === null || in_array($branch->id, $scope, true),
            ]),
            'companyOnly' => InvariantGuard::COMPANY_ONLY_GROUPS,
        ]);
    }

    /** §15-03: what one branch decides for itself, against what the company says. */
    public function branch(Request $request, Branch $branch): View
    {
        $this->assertBranchInScope($branch);

        $groups = [];

        foreach ((array) config('erp.settings.groups', []) as $key => $definition) {
            $companyOnly = $this->guard->isCompanyOnly($key);

            $fields = [];

            foreach ($definition['fields'] ?? [] as $fieldKey => $meta) {
                $companyValue = $this->settings->get($key, $fieldKey);

                $fields[$fieldKey] = [
                    'label' => $meta['label'] ?? $fieldKey,
                    'value' => $this->settings->get($key, $fieldKey, null, $branch->id),
                    'company_value' => $companyValue,
                    'overridden' => $this->settings->hasOverride($key, $fieldKey, $branch->id),
                ];
            }

            $groups[$key] = [
                'label' => $definition['label'] ?? ucfirst($key),
                'description' => $definition['description'] ?? '',
                'company_only' => $companyOnly,
                'fields' => $fields,
                'overridden' => count(array_filter($fields, fn (array $field): bool => $field['overridden'])),
            ];
        }

        return view('settings.branch', [
            'branch' => $branch,
            'groups' => $groups,
            'branches' => Branch::query()->orderBy('name')->get(),
        ]);
    }

    /** Write this branch's own values. Policy groups are refused by the guard. */
    public function updateBranch(Request $request, Branch $branch): RedirectResponse
    {
        $this->assertBranchInScope($branch);

        $submitted = (array) $request->input('settings', []);
        $writable = [];

        // Only the groups that may differ per branch, and — because the rules
        // come from the same metadata the company screen uses — only the values
        // that could have been typed into the company form either. A branch is
        // not a laxer door into the same table.
        foreach ($submitted as $group => $fields) {
            $definition = config("erp.settings.groups.{$group}");

            if (! is_array($definition) || ! is_array($fields) || $this->guard->isCompanyOnly((string) $group)) {
                continue;
            }

            $writable[(string) $group] = $definition;
        }

        $rules = [];
        $attributes = [];

        foreach ($writable as $group => $definition) {
            $rules["settings.{$group}"] = ['nullable', 'array'];

            foreach (GroupRules::fields($definition) as $key => $fieldRules) {
                $rules["settings.{$group}.{$key}"] = $fieldRules;
            }

            $attributes += GroupRules::attributes($definition, "settings.{$group}.");
        }

        $validated = $rules === [] ? [] : Validator::make(
            ['settings' => $submitted],
            ['settings' => ['required', 'array']] + $rules,
            [],
            $attributes,
        )->validate();

        $saved = 0;
        $refused = [];

        foreach ($writable as $group => $definition) {
            $values = [];

            foreach (array_keys((array) $definition['fields']) as $key) {
                if (array_key_exists($key, (array) ($validated['settings'][$group] ?? []))) {
                    $values[$key] = $validated['settings'][$group][$key];
                }
            }

            if ($values === []) {
                continue;
            }

            $result = $this->settings->setGroup($group, $values, $branch->id, $request->user());

            $saved += count($result['saved']);
            $refused = array_merge($refused, $result['rejected']);

            if ($group === 'localization') {
                app(Translator::class)->flushLocale();
            }
        }

        $redirect = back()->with('status', $saved === 0
            ? 'No changes to save.'
            : sprintf('Saved %d value(s) for %s. The company values are untouched.', $saved, $branch->name));

        if ($refused !== []) {
            $redirect->with('warning', 'Refused: '.implode(', ', $refused).' cannot be changed from a settings screen.');
        }

        return $redirect;
    }

    /** Remove an override, so the branch follows the company again. */
    public function forgetBranchSetting(Request $request, Branch $branch, string $group, string $key): RedirectResponse
    {
        $this->definition($group);
        $this->assertBranchInScope($branch);

        $removed = $this->settings->forget($group, $key, $branch->id, $request->user());

        return back()->with($removed ? 'status' : 'warning', $removed
            ? sprintf('%s.%s now follows the company value.', $group, $key)
            : sprintf('There was no override of %s.%s on %s.', $group, $key, $branch->name));
    }

    /* ------------------------------------------------------------------ internals */

    /** @return array<string, array<string, mixed>> */
    protected function definition(string $group): array
    {
        $definition = config("erp.settings.groups.{$group}");

        if (! is_array($definition) || ! isset($definition['fields'])) {
            abort(404, 'Unknown settings group.');
        }

        return $definition;
    }

    /**
     * The group's own key on top of the module floor: `settings.view` opens the
     * desk, `settings.<group>` opens that group. A reader who holds the floor but
     * not the group is told which key they need rather than shown a 404.
     */
    /**
     * A branch outside the actor's scope is a refusal the person can act on, not
     * a server error: the tenant context throws at the service edge, so the
     * screens check first and answer 403 with the reason.
     */
    protected function assertBranchInScope(Branch $branch): void
    {
        $user = auth()->user();
        $scope = $this->context->accessibleBranchIds();

        abort_unless(
            $user?->isSuperAdmin() === true || $scope === null || in_array($branch->id, $scope, true),
            403,
            sprintf('%s is outside your branch scope.', $branch->name),
        );
    }

    protected function authorizeGroup(?User $user, string $group, array $definition, bool $write = false): void
    {
        $key = (string) ($definition['key'] ?? 'settings.update');

        if ($user === null || (! $user->isSuperAdmin() && ! $user->can($key))) {
            abort(403, sprintf('This settings group needs the %s permission.', $key));
        }

        if ($write && ! $user->isSuperAdmin() && ! $user->can('settings.update')) {
            abort(403, 'Changing settings needs the settings.update permission.');
        }
    }
}
