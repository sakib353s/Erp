<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\Services\NavigationBuilder;
use App\Domain\Foundation\Services\OnboardingChecklist;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\Widget;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Workflow\ApprovalRequest;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dashboard (§46/§49-10): renders the onboarding checklist with REAL
 * completion state and exactly 25 widget containers (decision D22).
 *
 * Widgets whose data source exists in this foundation (pending
 * approvals, recent activity) render REAL numbers; the remaining
 * containers render truthful empty states until their module phases
 * wire them. No widget ever shows invented statistics.
 */
class DashboardController extends Controller
{
    public function __construct(
        protected OnboardingChecklist $checklist,
        protected PermissionCatalog $permissions,
        protected TenantContext $context,
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
            ->filter(fn (Widget $widget) => $widget->permission_id === null
                || $this->permissions->allows($user, $widget->permission?->key ?? ''))
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
            'realData' => [
                'pending_approvals' => $pendingApprovals,
                'recent_activity' => $recentActivity,
            ],
            'branch' => $this->context->branch(),
            'warehouse' => $this->context->warehouse(),
        ]);
    }
}
