<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\Warehouse;
use App\Http\Requests\SwitchContextRequest;
use App\Domain\Foundation\Services\Translator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Branch/warehouse switcher (Rules 4/5, decision D6): the client only
 * PROPOSES an id — every check (exists, active, user scope, branch
 * match) happens server-side here and in TenantContext on every
 * subsequent request. A manipulated id is rejected, never honoured.
 */
class ContextController extends Controller
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    public function switchBranch(SwitchContextRequest $request): JsonResponse|RedirectResponse
    {
        $branch = Branch::query()
            ->whereKey((int) $request->validated('branch_id'))
            ->where('is_active', true)
            ->first();

        abort_if($branch === null, 404, 'Branch not found.');

        $actor = $request->user();

        if (! $actor->hasBranchAccess($branch->id)) {
            abort(403, 'You do not have access to this branch.');
        }

        $request->session()->put('tenant.branch_id', $branch->id);
        $request->session()->forget('tenant.warehouse_id'); // re-resolved for the new branch
        $this->context->setBranch($branch);

        $this->audit->record([
            'action' => 'branch.switch',
            'entity_type' => 'branch',
            'entity_id' => $branch->id,
            'branch_id' => $branch->id,
            'actor_id' => $actor->id,
            'after' => ['branch' => $branch->name],
            'ip' => (string) $request->ip(),
        ]);

        return $this->respond($request, ['branch' => $branch->name]);
    }

    public function switchWarehouse(SwitchContextRequest $request): JsonResponse|RedirectResponse
    {
        $actor = $request->user();
        $branchId = (int) $this->context->branchId();

        $warehouse = Warehouse::query()
            ->whereKey((int) $request->validated('warehouse_id'))
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->first();

        abort_if($warehouse === null, 404, 'Warehouse not found in the current branch.');

        $request->session()->put('tenant.warehouse_id', $warehouse->id);
        $this->context->setWarehouse($warehouse);

        return $this->respond($request, ['warehouse' => $warehouse->name]);
    }

    /** UI language toggle (EN/BN — decision D21, session-scoped). */
    public function switchLocale(Request $request): RedirectResponse
    {
        $locale = (string) $request->input('locale');

        abort_unless(in_array($locale, ['en', 'bn'], true), 422, 'Unsupported locale.');

        $request->session()->put('locale', $locale);
        app(Translator::class)->setLocale($locale);
        app()->setLocale($locale);

        return back()->with('status', $locale === 'bn' ? 'ভাষা বাংলা করা হয়েছে।' : 'Language set to English.');
    }

    protected function respond(SwitchContextRequest $request, array $data): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true] + $data);
        }

        return back()->with('status', 'Context switched to '.reset($data).'.');
    }
}
