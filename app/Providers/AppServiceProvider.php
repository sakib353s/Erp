<?php

namespace App\Providers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\Policies\AuditPolicy;
use App\Domain\Documents\Document;
use App\Domain\Documents\Policies\DocumentPolicy;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Events\RolePermissionsChanged;
use App\Domain\Foundation\Listeners\InvalidatePermissionCache;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\Policies\BranchPolicy;
use App\Domain\Foundation\Policies\MenuItemPolicy;
use App\Domain\Foundation\Policies\RolePolicy;
use App\Domain\Foundation\Policies\UserPolicy;
use App\Domain\Foundation\Policies\WarehousePolicy;
use App\Domain\Foundation\Role;
use App\Domain\Foundation\Services\EntitlementService;
use App\Domain\Foundation\Services\NavigationBuilder;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\Services\Translator;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Notification\Services\NotificationCenter;
use App\Domain\Sales\Events\QuotationSent;
use App\Domain\Sales\Listeners\NotifyCreatorOnQuotationSent;
use App\Domain\Security\Events\LoginFailed;
use App\Domain\Security\Events\LoginSucceeded;
use App\Domain\Security\Listeners\RaiseSecurityAlerts;
use App\Domain\Settings\Policies\SettingPolicy;
use App\Domain\Settings\Services\SettingService;
use App\Domain\Settings\Setting;
use App\Domain\Workflow\ApprovalRequest;
use App\Domain\Workflow\Events\WorkflowApproved;
use App\Domain\Workflow\Events\WorkflowCancelled;
use App\Domain\Workflow\Events\WorkflowEscalated;
use App\Domain\Workflow\Events\WorkflowRejected;
use App\Domain\Workflow\Events\WorkflowReturned;
use App\Domain\Workflow\Events\WorkflowSubmitted;
use App\Domain\Workflow\Listeners\ApplyApprovedPriceBulkUpdate;
use App\Domain\Workflow\Listeners\NotifyPendingApprovers;
use App\Domain\Workflow\Listeners\NotifySubmitterOnDecision;
use App\Domain\Workflow\Listeners\PricingRuleOverrideDecision;
use App\Domain\Workflow\Policies\ApprovalPolicy;
use App\Domain\Workflow\Policies\WorkflowPolicy;
use App\Domain\Workflow\WorkflowDefinition;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The trusted per-request context MUST be a singleton: every
        // service, scope and policy reads the same instance (decision D6).
        $this->app->singleton(TenantContext::class, fn () => new TenantContext);
        $this->app->singleton(PermissionCatalog::class);
        $this->app->singleton(EntitlementService::class);
        $this->app->singleton(SettingService::class);
        $this->app->singleton(NavigationBuilder::class);
        $this->app->singleton(NotificationCenter::class);
        $this->app->singleton(Translator::class);
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // `@t('key', 'fallback')` in Blade, with the same fallback chain as the
        // `t()` global (requested locale → English → the key itself), so a view
        // author never reaches past the translation table into a missing row.
        Blade::directive('t', function (string $expression) {
            return "<?php echo e(app(\\App\\Domain\\Foundation\\Services\\Translator::class)->get($expression)); ?>";
        });

        // Shared view helpers: DB-driven translation (D21) and a permission
        // probe for hiding controls (server-side gates remain authoritative).
        View::share(
            'tr',
            fn (?string $key = null, ?string $fallback = null) => app(Translator::class)->get((string) $key, $fallback),
        );
        View::share(
            'perm',
            fn (string $key) => auth()->check()
                ? app(PermissionCatalog::class)->allows(auth()->user(), $key)
                : false,
        );

        /* ---------------- Authorization (Rules 6 & 7) ---------------- */

        /*
         * Rule 6 — permissions live in the database, never in Gate::define()
         * calls. `$user->can('business.assets.manage')` therefore asks the
         * same catalogue the `permission:` middleware asks, so a form request
         * and its route can never disagree about who may act. Abilities the
         * catalogue does not know return null ("no opinion") and fall through
         * to the policies below; a super admin still holds everything.
         */
        Gate::before(function (User $user, string $ability) {
            if ($user->isSuperAdmin()) {
                return true;
            }

            return app(PermissionCatalog::class)->allows($user, $ability) ? true : null;
        });

        Gate::policy(Branch::class, BranchPolicy::class);
        Gate::policy(Warehouse::class, WarehousePolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(MenuItem::class, MenuItemPolicy::class);
        Gate::policy(Setting::class, SettingPolicy::class);
        Gate::policy(WorkflowDefinition::class, WorkflowPolicy::class);
        Gate::policy(ApprovalRequest::class, ApprovalPolicy::class);
        Gate::policy(AuditEvent::class, AuditPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);

        /* ---------------- Domain events → listeners ---------------- */
        // Workflow/security events are published through the OUTBOX and
        // dispatched strictly AFTER commit (decision D13). Listeners are
        // idempotent (dedupe keys), so outbox retries are safe.

        Event::listen(WorkflowSubmitted::class, [NotifyPendingApprovers::class, 'handle']);
        Event::listen(WorkflowApproved::class, [NotifySubmitterOnDecision::class, 'handle']);
        // Bulk price updates re-queue themselves once approved (02-109).
        Event::listen(WorkflowApproved::class, [ApplyApprovedPriceBulkUpdate::class, 'handle']);
        // Pricing-rule overrides apply/reject once decided (02-113).
        Event::listen(WorkflowApproved::class, [PricingRuleOverrideDecision::class, 'handle']);
        Event::listen(WorkflowRejected::class, [PricingRuleOverrideDecision::class, 'handle']);
        Event::listen(WorkflowRejected::class, [NotifySubmitterOnDecision::class, 'handle']);
        Event::listen(WorkflowReturned::class, [NotifySubmitterOnDecision::class, 'handle']);
        Event::listen(WorkflowCancelled::class, [NotifySubmitterOnDecision::class, 'handle']);
        Event::listen(WorkflowEscalated::class, [NotifySubmitterOnDecision::class, 'handle']);

        Event::listen(LoginSucceeded::class, [RaiseSecurityAlerts::class, 'onLoginSucceeded']);
        Event::listen(LoginFailed::class, [RaiseSecurityAlerts::class, 'onLoginFailed']);

        // Sales deliveries ride the same outbox (02-67): the send action and
        // its notification land together, retried together.
        Event::listen(QuotationSent::class, [NotifyCreatorOnQuotationSent::class, 'handle']);

        // Synchronous cache invalidation — the very next request must see
        // the new permissions (Rule 6).
        Event::listen(RolePermissionsChanged::class, [InvalidatePermissionCache::class, 'handle']);
    }
}
