<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Dashboard\Services\DashboardMetrics;
use App\Domain\Foundation\Services\NavigationBuilder;
use App\Domain\Foundation\Services\OnboardingChecklist;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Widget;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Workflow\ApprovalRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dashboard (§46/§49-10): renders the onboarding checklist with REAL
 * completion state and exactly 25 widget containers (decision D22).
 *
 * Every container is answered by DashboardMetrics from the company's own
 * documents: a figure, an honest empty state, or a plain statement that its
 * module has no source yet. No widget ever shows an invented statistic, and a
 * container whose module the user may not read is not rendered at all.
 */
class DashboardController extends Controller
{
    public function __construct(
        protected OnboardingChecklist $checklist,
        protected PermissionCatalog $permissions,
        protected TenantContext $context,
        protected DashboardMetrics $metrics,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $widgets = Widget::query()
            ->where('container', 'dashboard')
            ->where('is_active', true)
            ->orderBy('sort')
            ->with('permission')
            ->get()
            ->filter(fn (Widget $widget) => $this->allowsContainer($user, $widget))
            ->values();

        $pendingApprovals = (int) ApprovalRequest::query()->where('status', 'pending')->count();

        $recentActivity = AuditEvent::query()
            ->orderByDesc('id')
            ->limit(8)
            ->get(['id', 'action', 'entity_type', 'entity_id', 'result', 'created_at'])
            ->map(fn (AuditEvent $event) => [
                'action' => $event->action,
                'entity_type' => $event->entity_type,
                'entity_id' => $event->entity_id,
                'result' => $event->result,
                'created_at' => $event->created_at,
            ]);

        return view('dashboard.index', [
            'widgets' => $widgets,
            'checklist' => $this->checklist->steps(),
            'checklistComplete' => $this->checklist->complete(),
            // Only the containers this user may see are computed, and each one
            // arrives as a figure, an honest empty state, or a plain statement
            // that its module has no source yet.
            'metrics' => $this->metrics->for($widgets->pluck('code')->all()),
            'realData' => [
                'pending_approvals' => $pendingApprovals,
                'recent_activity' => $recentActivity,
            ],
            'branch' => $this->context->branch(),
            'warehouse' => $this->context->warehouse(),
        ]);
    }

    /**
     * A container is visible when both gates pass: the widget's own permission
     * row, and the view permission of the module whose documents the panel
     * reads. The second gate is what stops a user who may not read invoices
     * from seeing invoice totals — and it is the same key that protects the
     * screen the panel drills into.
     */
    protected function allowsContainer(User $user, Widget $widget): bool
    {
        if ($widget->permission_id !== null
            && ! $this->permissions->allows($user, $widget->permission?->key ?? '')) {
            return false;
        }

        $moduleKey = $this->metrics->permissionFor($widget->code);

        return $moduleKey === null || $this->permissions->allows($user, $moduleKey);
    }

    /**
     * One container's figures on their own (01-02…01-26). The dashboard page
     * renders the same payload inline; this endpoint is what a refresh uses, and
     * it refuses a container the caller has no business reading.
     */
    public function widget(Request $request, string $widget): JsonResponse
    {
        $user = $request->user();

        $container = Widget::query()
            ->where('code', $widget)
            ->where('container', 'dashboard')
            ->where('is_active', true)
            ->with('permission')
            ->first();

        abort_if($container === null, 404);

        abort_unless($this->allowsContainer($user, $container), 403);

        $metric = $this->metrics->for([$widget])[$widget] ?? null;

        abort_if($metric === null, 404, 'That container has no metric yet.');

        return response()->json([
            'code' => $container->code,
            'label' => $container->label,
            'generated_at' => now()->toIso8601String(),
            ...$metric,
        ]);
    }
}
