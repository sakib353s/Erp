<?php

use App\Domain\Masters\Support\MasterCatalog;
use App\Domain\Reporting\ReportRegistry;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankChargeController;
use App\Http\Controllers\BankReconciliationController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\BusinessRecordController;
use App\Http\Controllers\BulkPriceUpdateController;
use App\Http\Controllers\CashBankController;
use App\Http\Controllers\CashReportController;
use App\Http\Controllers\CashCountController;
use App\Http\Controllers\ChequeController;
use App\Http\Controllers\PettyCashController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\CodController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ContextController;
use App\Http\Controllers\CourierPartnerController;
use App\Http\Controllers\CourierProviderController;
use App\Http\Controllers\CustomReportController;
use App\Http\Controllers\ReportCentreController;
use App\Http\Controllers\NavigationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryZoneController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\HrController;
use App\Http\Controllers\PurchaseBillController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseReturnController;
use App\Http\Controllers\SupplierPaymentController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\FailedDeliveryController;
use App\Http\Controllers\FinancialReportController;
use App\Http\Controllers\InventoryBatchController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryReportController;
use App\Http\Controllers\InventoryCountController;
use App\Http\Controllers\InventoryDamageController;
use App\Http\Controllers\InventoryPackagingController;
use App\Http\Controllers\InventoryLabelController;
use App\Http\Controllers\InventoryReservationController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PackagingController;
use App\Http\Controllers\NoticeController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PosCustomerDisplayController;
use App\Http\Controllers\PosSettingsController;
use App\Http\Controllers\PriceCompareController;
use App\Http\Controllers\PriceHistoryController;
use App\Http\Controllers\PickListController;
use App\Http\Controllers\ReorderSuggestionController;
use App\Http\Controllers\PriceListController;
use App\Http\Controllers\PricingRuleController;
use App\Http\Controllers\PutawayListController;
use App\Http\Controllers\ProductCostHistoryController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProofOfDeliveryController;
use App\Http\Controllers\PublicShareController;
use App\Http\Controllers\RiderAssignmentController;
use App\Http\Controllers\RiderCodController;
use App\Http\Controllers\RiderController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\RouteOptimizerController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\TrackingEventController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\WorkflowController;
use App\Http\Controllers\ZoneChargeController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app/dashboard');
Route::redirect('/app', '/app/dashboard');

/*
 |--------------------------------------------------------------------------
 | First boot (spec §49)
 |--------------------------------------------------------------------------
 | Reachable ONLY while the instance is unconfigured (GuardSetup aborts
 | with 403 the moment a company exists — setup can never re-run).
 */
Route::middleware('setup.open')->group(function () {
    Route::get('/setup', [SetupController::class, 'show'])->name('setup.show');
    Route::post('/setup', [SetupController::class, 'store'])->middleware('throttle:5,1')->name('setup.store');
});

Route::middleware(['guest', 'setup.complete'])->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
});

/*
 | Public share links (02-68) — no session identity by design: the token
 | is the capability, every visit is logged to public_access_logs.
 */
Route::get('/share/quotation/{token}', [PublicShareController::class, 'quotation'])
    ->middleware('throttle:30,1')
    ->where('token', '[A-Za-z0-9]{32,64}')
    ->name('share.quotation');

/*
 | Courier tracking webhook (02-90) — no session identity by design:
 | the HMAC signature over the raw body is the capability. Unsigned or
 | mis-signed pushes are rejected before any parsing happens, and a
 | courier without a configured signing secret can never be verified.
 */
Route::post('/webhooks/couriers/{courier}', [TrackingEventController::class, 'webhook'])
    ->middleware('throttle:60,1')
    ->name('couriers.webhook');

/*
 |--------------------------------------------------------------------------
 | Authenticated application shell
 |--------------------------------------------------------------------------
 | tenant  = server-side company/branch/warehouse context rebuild (D6)
 | portal:erp = ERP portal isolation (correction E)
 | permission:* = DB-driven permission matrix (Rules 6/7), checked per route
 */
Route::middleware(['auth', 'setup.complete', 'tenant', 'portal:erp'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    /* ---- Context switching (server-side re-validated — Rules 4/5) ---- */
    Route::post('/app/context/branch', [ContextController::class, 'switchBranch'])->name('context.branch');
    Route::post('/app/context/warehouse', [ContextController::class, 'switchWarehouse'])->name('context.warehouse');
    Route::post('/app/context/locale', [ContextController::class, 'switchLocale'])->name('context.locale');

    /* ---- Foundation pages ---- */
    Route::get('/app/dashboard', [DashboardController::class, 'index'])
        ->middleware(['permission:dashboard.view', 'feature:dashboard'])
        ->name('dashboard');
    Route::get('/app/dashboard/widgets/{widget}', [DashboardController::class, 'widget'])
        ->middleware(['permission:dashboard.view', 'feature:dashboard'])
        ->name('dashboard.widget');

    /* ---- Personal navigation: pin/unpin a destination (§18.3) ---- */
    Route::post('/app/navigation/pin', [NavigationController::class, 'pin'])
        ->name('navigation.pin');

    /* ---- Global search (D17 / row 16-49): auth only, per-entity
           permissions enforced inside SearchService ---- */
    Route::get('/search', [SearchController::class, 'index'])
        ->name('search.index');

    /* ---- System maintenance (§15-23 … §15-33) ----
           One key per kind of damage a person can do here: clearing a cache is
           not repairing a table, and repairing a table is not resetting the
           company's settings back to defaults. The desk itself is the entry
           point; each button carries the key for what it actually does. */
    Route::get('/app/maintenance', [MaintenanceController::class, 'index'])
        ->middleware('permission:maintenance.index')
        ->name('maintenance.index');

    Route::post('/maintenance/cache/clear', [MaintenanceController::class, 'clearCache'])
        ->middleware('permission:maintenance.cache')
        ->name('maintenance.cache.clear');
    Route::post('/maintenance/sessions/clear', [MaintenanceController::class, 'clearSessions'])
        ->middleware('permission:maintenance.sessions')
        ->name('maintenance.sessions.clear');
    Route::post('/maintenance/temp/clear', [MaintenanceController::class, 'clearTemp'])
        ->middleware('permission:maintenance.temp')
        ->name('maintenance.temp.clear');

    Route::post('/maintenance/database/optimize', [MaintenanceController::class, 'optimize'])
        ->middleware('permission:maintenance.database')
        ->name('maintenance.database.optimize');
    Route::post('/maintenance/database/integrity', [MaintenanceController::class, 'integrity'])
        ->middleware('permission:maintenance.database')
        ->name('maintenance.database.integrity');
    Route::post('/maintenance/database/repair', [MaintenanceController::class, 'repair'])
        ->middleware('permission:maintenance.repair')
        ->name('maintenance.database.repair');

    Route::post('/maintenance/rebuild-index', [MaintenanceController::class, 'rebuildIndex'])
        ->middleware('permission:maintenance.index')
        ->name('maintenance.rebuild-index');
    Route::post('/maintenance/self-heal', [MaintenanceController::class, 'selfHeal'])
        ->middleware('permission:maintenance.heal')
        ->name('maintenance.self-heal');
    Route::post('/maintenance/settings/reset', [MaintenanceController::class, 'resetSettings'])
        ->middleware('permission:maintenance.reset')
        ->name('maintenance.settings.reset');

    Route::get('/app/maintenance/logs', [MaintenanceController::class, 'logs'])
        ->middleware('permission:maintenance.logs')
        ->name('maintenance.logs');

    Route::get('/app/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::post('/app/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/app/profile/password', [ProfileController::class, 'changePassword'])->name('profile.password');

    /* ---- Notification centre ---- */
    Route::get('/app/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/app/notifications/poll', [NotificationController::class, 'poll'])->name('notifications.poll');
    Route::post('/app/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read_all');
    Route::post('/app/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

    /* ---- Company profile (15-02) — registered BEFORE {group} wildcard ---- */
    Route::get('/app/settings/company', [CompanyController::class, 'edit'])
        ->middleware('permission:settings.company')
        ->name('company.edit');
    Route::put('/app/settings/company', [CompanyController::class, 'update'])
        ->middleware('permission:settings.company')
        ->name('company.update');

    /* ---- Courier partners (02-91) — must precede the /settings/{group} catch-all ---- */
    Route::get('/app/settings/couriers', [CourierPartnerController::class, 'index'])
        ->middleware('permission:sales.delivery.configure')
        ->name('couriers.index');
    Route::post('/app/settings/couriers', [CourierPartnerController::class, 'store'])
        ->middleware('permission:sales.delivery.configure')
        ->name('couriers.store');

    /* ---- Per-provider courier config (02-92) — same catch-all caveat ---- */
    Route::get('/app/settings/couriers/{provider}', [CourierProviderController::class, 'show'])
        ->middleware('permission:sales.delivery.configure')
        ->name('couriers.provider.show');
    Route::put('/app/settings/couriers/{provider}', [CourierProviderController::class, 'update'])
        ->middleware('permission:sales.delivery.configure')
        ->name('couriers.provider.update');

    /* ---- POS settings (02-47) — literal URI, must precede the
           /app/settings/{group} catch-all (same-URI routes would be
           replaced by the later registration) ---- */
    Route::get('/app/settings/pos', [PosSettingsController::class, 'show'])
        ->middleware('permission:pos.settings.configure')
        ->name('settings.pos.show');
    Route::post('/app/settings/pos', [PosSettingsController::class, 'update'])
        ->middleware('permission:pos.settings.configure')
        ->name('settings.pos.update');

    /*
     * §15 — the settings desk and branch scope.
     *
     * The index is the module's own landing page: every group, how many of its
     * fields are really set, who set them last, and the key each group needs.
     * The group screens themselves are behind their own key now (checked in the
     * controller, because one route serves fifteen groups), and the branch screen
     * is where an outlet sets what it may decide for itself — with the ability to
     * remove an override so it follows the company again. Company policy
     * (security, audit, workflow, notifications) is listed there too, with the
     * reason it cannot be overridden.
     *
     * These sit before the {group} catch-all on purpose: `branches/{branch}` is
     * two segments and would not match it anyway, but the ordering is what keeps
     * a future one-segment literal from being swallowed.
     */
    Route::get('/app/settings', [SettingController::class, 'index'])
        ->middleware('permission:settings.view')
        ->name('settings.index');
    Route::get('/app/settings/branches', [SettingController::class, 'branches'])
        ->middleware('permission:settings.branch')
        ->name('settings.branches');
    Route::get('/app/settings/branches/{branch}', [SettingController::class, 'branch'])
        ->middleware('permission:settings.branch')
        ->name('settings.branch.show');
    Route::post('/app/settings/branches/{branch}', [SettingController::class, 'updateBranch'])
        ->middleware('permission:settings.branch')
        ->name('settings.branch.update');
    Route::delete('/app/settings/branches/{branch}/{group}/{key}', [SettingController::class, 'forgetBranchSetting'])
        ->middleware('permission:settings.branch')
        ->name('settings.branch.forget');

    /* ---- Settings ---- */
    Route::get('/app/settings/{group}', [SettingController::class, 'show'])
        ->middleware('permission:settings.view')
        ->name('settings.show');
    Route::post('/app/settings/{group}', [SettingController::class, 'update'])
        ->middleware('permission:settings.update')
        ->name('settings.update');

    /* ---- Users ---- */
    Route::middleware('permission:users.view')->group(function () {
        Route::get('/app/users', [UserController::class, 'index'])->name('users.index');
    });
    // Literal '/create' is registered BEFORE the '{user}' wildcard so the URI
    // resolves to the form instead of being swallowed by implicit binding.
    Route::get('/app/users/create', [UserController::class, 'create'])
        ->middleware('permission:users.create')
        ->name('users.create');
    Route::post('/app/users', [UserController::class, 'store'])
        ->middleware('permission:users.create')
        ->name('users.store');
    Route::middleware('permission:users.view')->group(function () {
        Route::get('/app/users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::get('/app/users/{user}/access', [UserController::class, 'access'])->name('users.access');
    });
    Route::get('/app/users/{user}/edit', [UserController::class, 'edit'])
        ->middleware('permission:users.update')
        ->name('users.edit');
    Route::put('/app/users/{user}', [UserController::class, 'update'])
        ->middleware('permission:users.update')
        ->name('users.update');
    Route::post('/app/users/{user}/suspend', [UserController::class, 'suspend'])
        ->middleware('permission:users.update')
        ->name('users.suspend');
    Route::post('/app/users/{user}/activate', [UserController::class, 'activate'])
        ->middleware('permission:users.update')
        ->name('users.activate');
    Route::delete('/app/users/{user}', [UserController::class, 'destroy'])
        ->middleware('permission:users.delete')
        ->name('users.destroy');

    /* ---- Employees (10-01 … 10-03) ---- */
    Route::middleware('permission:employees.view')->group(function () {
        Route::get('/app/employees', [EmployeeController::class, 'index'])->name('employees.index');
    });
    Route::get('/app/employees/create', [EmployeeController::class, 'create'])
        ->middleware('permission:employees.create')
        ->name('employees.create');
    Route::post('/app/employees', [EmployeeController::class, 'store'])
        ->middleware('permission:employees.create')
        ->name('employees.store');
    Route::middleware('permission:employees.view')->group(function () {
        Route::get('/app/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
    });
    Route::get('/app/employees/{employee}/edit', [EmployeeController::class, 'edit'])
        ->middleware('permission:employees.edit')
        ->name('employees.edit');
    Route::put('/app/employees/{employee}', [EmployeeController::class, 'update'])
        ->middleware('permission:employees.edit')
        ->name('employees.update');
    Route::delete('/app/employees/{employee}', [EmployeeController::class, 'destroy'])
        ->middleware('permission:employees.edit')
        ->name('employees.destroy');

    /* ---- HRM (§10.1…§10.2) — attendance, leave, structure ---- */
    Route::middleware('permission:attendance.view')->group(function () {
        Route::get('/app/hr/attendance', [HrController::class, 'attendance'])->name('hr.attendance');
        Route::get('/app/hr/attendance/summary', [HrController::class, 'attendanceSummary'])->name('hr.attendance.summary');
        Route::get('/app/hr/attendance/report/{employee}', [HrController::class, 'attendanceReport'])
            ->middleware('permission:attendance.report')->name('hr.attendance.report');
    });
    Route::post('/app/hr/attendance', [HrController::class, 'markAttendance'])
        ->middleware('permission:attendance.manage')->name('hr.attendance.mark');
    Route::post('/app/hr/attendance/non-working', [HrController::class, 'markNonWorking'])
        ->middleware('permission:attendance.manage')->name('hr.attendance.non-working');

    Route::middleware('permission:leave.view')->group(function () {
        Route::get('/app/hr/leave', [HrController::class, 'leave'])->name('hr.leave');
        Route::get('/app/hr/leave/calendar', [HrController::class, 'leaveCalendar'])->name('hr.leave.calendar');
    });
    Route::post('/app/hr/leave', [HrController::class, 'storeLeave'])
        ->middleware('permission:leave.request')->name('hr.leave.store');
    Route::post('/app/hr/leave/{leave}/decide', [HrController::class, 'decideLeave'])
        ->middleware('permission:leave.approve')->name('hr.leave.decide');

    Route::middleware('permission:hr.structure.manage')->group(function () {
        Route::get('/app/hr/departments', [HrController::class, 'departments'])->name('hr.departments');
        Route::post('/app/hr/departments', [HrController::class, 'storeDepartment'])->name('hr.departments.store');
        Route::get('/app/hr/designations', [HrController::class, 'designations'])->name('hr.designations');
        Route::post('/app/hr/designations', [HrController::class, 'storeDesignation'])->name('hr.designations.store');
        Route::get('/app/hr/leave-types', [HrController::class, 'leaveTypes'])->name('hr.leave-types');
        Route::post('/app/hr/leave-types', [HrController::class, 'storeLeaveType'])->name('hr.leave-types.store');
    });

    Route::middleware('permission:hr.structure.manage')->group(function () {
        Route::get('/app/hr/service-book/{employee}', [HrController::class, 'serviceBook'])->name('hr.service-book');
    });
    /* ---- Roles & permissions ---- */
    Route::middleware('permission:roles.view')->group(function () {
        Route::get('/app/roles', [RoleController::class, 'index'])->name('roles.index');
    });
    Route::get('/app/roles/create', [RoleController::class, 'create'])
        ->middleware('permission:roles.create')
        ->name('roles.create');
    Route::post('/app/roles', [RoleController::class, 'store'])
        ->middleware('permission:roles.create')
        ->name('roles.store');
    Route::middleware('permission:roles.view')->group(function () {
        Route::get('/app/roles/{role}', [RoleController::class, 'show'])->name('roles.show');
    });
    Route::get('/app/roles/{role}/edit', [RoleController::class, 'edit'])
        ->middleware('permission:roles.update')
        ->name('roles.edit');
    Route::put('/app/roles/{role}', [RoleController::class, 'update'])
        ->middleware('permission:roles.update')
        ->name('roles.update');
    Route::delete('/app/roles/{role}', [RoleController::class, 'destroy'])
        ->middleware('permission:roles.delete')
        ->name('roles.destroy');

    /* ---- Branches ---- */
    Route::middleware('permission:branches.view')->group(function () {
        Route::get('/app/branches', [BranchController::class, 'index'])->name('branches.index');
    });

    /* §12-07 — the branches side by side. Literal segment first: `/compare`
       must never be read as a branch id. */
    Route::get('/app/branches/compare', [BranchController::class, 'compare'])
        ->middleware('permission:branches.compare')
        ->name('branches.compare');

    /* §12-08 — every transfer that crossed a branch boundary, then the desk for
       one branch. Both literal-first: `/transfer` is not a branch id. */
    Route::get('/app/branches/transfer', [BranchController::class, 'transferOverview'])
        ->middleware('permission:branches.view')
        ->name('branches.transfers');

    /* §12-08 — the branch transfer desk: what has moved, and the way to move
       more through the stock-transfer engine. */
    Route::middleware('permission:branches.view')->group(function () {
        Route::get('/app/branches/{branch}/transfer', [BranchController::class, 'transfer'])
            ->name('branches.transfer');
    });
    Route::post('/app/branches/{branch}/transfer', [BranchController::class, 'raiseTransfer'])
        ->middleware('permission:branches.transfer')
        ->name('branches.transfer.store');
    Route::get('/app/branches/create', [BranchController::class, 'create'])
        ->middleware('permission:branches.create')
        ->name('branches.create');
    Route::post('/app/branches', [BranchController::class, 'store'])
        ->middleware('permission:branches.create')
        ->name('branches.store');
    Route::middleware('permission:branches.view')->group(function () {
        Route::get('/app/branches/{branch}', [BranchController::class, 'show'])->name('branches.show');
    });
    Route::get('/app/branches/{branch}/edit', [BranchController::class, 'edit'])
        ->middleware('permission:branches.update')
        ->name('branches.edit');
    Route::put('/app/branches/{branch}', [BranchController::class, 'update'])
        ->middleware('permission:branches.update')
        ->name('branches.update');
    Route::delete('/app/branches/{branch}', [BranchController::class, 'destroy'])
        ->middleware('permission:branches.delete')
        ->name('branches.destroy');

    /* ---- Warehouses ---- */
    Route::middleware('permission:warehouses.view')->group(function () {
        Route::get('/app/warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
    });
    Route::get('/app/warehouses/create', [WarehouseController::class, 'create'])
        ->middleware('permission:warehouses.create')
        ->name('warehouses.create');
    Route::post('/app/warehouses', [WarehouseController::class, 'store'])
        ->middleware('permission:warehouses.create')
        ->name('warehouses.store');
    Route::get('/app/warehouses/{warehouse}/edit', [WarehouseController::class, 'edit'])
        ->middleware('permission:warehouses.update')
        ->name('warehouses.edit');
    Route::put('/app/warehouses/{warehouse}', [WarehouseController::class, 'update'])
        ->middleware('permission:warehouses.update')
        ->name('warehouses.update');
    Route::delete('/app/warehouses/{warehouse}', [WarehouseController::class, 'destroy'])
        ->middleware('permission:warehouses.delete')
        ->name('warehouses.destroy');

    /* ---- §04-43/04-45 Zones, bins, product bin assignment and the map ----
       Reading the layout is `warehouses.view`; changing it is `warehouses.update`,
       because a zone or a bin *is* the warehouse's shape — the physical layout
       does not get a permission key of its own. */
    Route::get('/app/warehouses/{warehouse}', [WarehouseController::class, 'show'])
        ->middleware('permission:warehouses.view')
        ->name('warehouses.show');
    Route::post('/app/warehouses/{warehouse}/zones', [WarehouseController::class, 'storeZone'])
        ->middleware('permission:warehouses.update')
        ->name('warehouses.zones.store');
    Route::delete('/app/warehouse-zones/{zone}', [WarehouseController::class, 'destroyZone'])
        ->middleware('permission:warehouses.update')
        ->name('warehouses.zones.destroy');
    Route::post('/app/warehouse-zones/{zone}/bins', [WarehouseController::class, 'storeBin'])
        ->middleware('permission:warehouses.update')
        ->name('warehouses.bins.store');
    Route::delete('/app/warehouse-bins/{bin}', [WarehouseController::class, 'destroyBin'])
        ->middleware('permission:warehouses.update')
        ->name('warehouses.bins.destroy');
    Route::post('/app/warehouses/{warehouse}/assignments', [WarehouseController::class, 'assignBin'])
        ->middleware('permission:warehouses.update')
        ->name('warehouses.bins.assign');
    Route::delete('/app/bin-assignments/{assignment}', [WarehouseController::class, 'unassignBin'])
        ->middleware('permission:warehouses.update')
        ->name('warehouses.bins.unassign');

    /* ---- §04-44 Pick lists and putaway lists ----
       The two instructions goods follow inside a warehouse. Reading a walk is
       `warehouses.view`; writing one down, handing it out and finishing it is
       `warehouses.update` — the same key the layout uses, because a bin is a
       place and this is the work that happens in it. Literal '/create' is
       registered before the {pickList} wildcard. */
    Route::get('/app/inventory/pick-lists', [PickListController::class, 'index'])
        ->middleware('permission:warehouses.view')
        ->name('inventory.pick-lists.index');
    Route::get('/app/inventory/pick-lists/create', [PickListController::class, 'create'])
        ->middleware('permission:warehouses.update')
        ->name('inventory.pick-lists.create');
    Route::post('/app/inventory/pick-lists', [PickListController::class, 'store'])
        ->middleware('permission:warehouses.update')
        ->name('inventory.pick-lists.store');
    Route::get('/app/inventory/pick-lists/{pickList}', [PickListController::class, 'show'])
        ->middleware('permission:warehouses.view')
        ->name('inventory.pick-lists.show');
    Route::post('/app/inventory/pick-lists/{pickList}/assign', [PickListController::class, 'assign'])
        ->middleware('permission:warehouses.update')
        ->name('inventory.pick-lists.assign');
    Route::post('/app/inventory/pick-lists/{pickList}/pick', [PickListController::class, 'recordPick'])
        ->middleware('permission:warehouses.update')
        ->name('inventory.pick-lists.pick');
    Route::post('/app/inventory/pick-lists/{pickList}/complete', [PickListController::class, 'complete'])
        ->middleware('permission:warehouses.update')
        ->name('inventory.pick-lists.complete');
    Route::post('/app/inventory/pick-lists/{pickList}/cancel', [PickListController::class, 'cancel'])
        ->middleware('permission:warehouses.update')
        ->name('inventory.pick-lists.cancel');

    Route::get('/app/inventory/putaway-lists', [PutawayListController::class, 'index'])
        ->middleware('permission:warehouses.view')
        ->name('inventory.putaway-lists.index');
    Route::get('/app/inventory/putaway-lists/create', [PutawayListController::class, 'create'])
        ->middleware('permission:warehouses.update')
        ->name('inventory.putaway-lists.create');
    Route::post('/app/inventory/putaway-lists', [PutawayListController::class, 'store'])
        ->middleware('permission:warehouses.update')
        ->name('inventory.putaway-lists.store');
    Route::get('/app/inventory/putaway-lists/{putawayList}', [PutawayListController::class, 'show'])
        ->middleware('permission:warehouses.view')
        ->name('inventory.putaway-lists.show');
    Route::post('/app/inventory/putaway-lists/{putawayList}/assign', [PutawayListController::class, 'assign'])
        ->middleware('permission:warehouses.update')
        ->name('inventory.putaway-lists.assign');
    Route::post('/app/inventory/putaway-lists/{putawayList}/place', [PutawayListController::class, 'recordPlacement'])
        ->middleware('permission:warehouses.update')
        ->name('inventory.putaway-lists.place');
    Route::post('/app/inventory/putaway-lists/{putawayList}/complete', [PutawayListController::class, 'complete'])
        ->middleware('permission:warehouses.update')
        ->name('inventory.putaway-lists.complete');
    Route::post('/app/inventory/putaway-lists/{putawayList}/cancel', [PutawayListController::class, 'cancel'])
        ->middleware('permission:warehouses.update')
        ->name('inventory.putaway-lists.cancel');

    /* ---- Customers / CRM (§05) ----
       The customer master existed so sales documents could reference a party;
       this block adds the CRM itself: profile, ledger, ageing, credit control
       and the relationship surfaces (addresses, contacts, feedback, referrals,
       wishlist). Literal '/create' and '/export' are registered BEFORE the
       {customer} wildcard so implicit binding cannot swallow them. */
    Route::get('/app/customers/export', [CustomerController::class, 'export'])
        ->middleware('permission:customers.export')
        ->name('customers.export');
    Route::get('/app/customers/create', [CustomerController::class, 'create'])
        ->middleware('permission:customers.create')
        ->name('customers.create');
    Route::get('/app/customers/due', [CustomerController::class, 'due'])
        ->middleware('permission:customers.due.view')
        ->name('customers.due');
    Route::get('/app/customers/groups', [CustomerController::class, 'groups'])
        ->middleware('permission:customers.groups')
        ->name('customers.groups');
    Route::post('/app/customers/groups', [CustomerController::class, 'storeGroup'])
        ->middleware('permission:customers.groups')
        ->name('customers.groups.store');
    Route::middleware('permission:customers.view')->group(function () {
        Route::get('/app/customers', [CustomerController::class, 'index'])->name('customers.index');
    });
    Route::post('/app/customers', [CustomerController::class, 'store'])
        ->middleware('permission:customers.create')
        ->name('customers.store');
    Route::middleware('permission:customers.view')->group(function () {
        Route::get('/app/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        Route::get('/app/customers/{customer}/open-invoices', [CustomerController::class, 'openInvoices'])
            ->name('customers.open-invoices');
        Route::get('/app/customers/{customer}/ledger', [CustomerController::class, 'ledger'])
            ->name('customers.ledger');
        Route::get('/app/customers/{customer}/statement', [CustomerController::class, 'statement'])
            ->name('customers.statement');
    });
    Route::get('/app/customers/{customer}/edit', [CustomerController::class, 'edit'])
        ->middleware('permission:customers.edit')
        ->name('customers.edit');
    Route::put('/app/customers/{customer}', [CustomerController::class, 'update'])
        ->middleware('permission:customers.edit')
        ->name('customers.update');
    Route::put('/app/customers/{customer}/credit-limit', [CustomerController::class, 'updateCreditLimit'])
        ->middleware('permission:customers.credit_limit')
        ->name('customers.credit-limit');
    Route::post('/app/customers/{customer}/blacklist', [CustomerController::class, 'blacklist'])
        ->middleware('permission:customers.blacklist')
        ->name('customers.blacklist');
    Route::post('/app/customers/{customer}/addresses', [CustomerController::class, 'storeAddress'])
        ->middleware('permission:customers.edit')
        ->name('customers.addresses.store');
    Route::delete('/app/customers/{customer}/addresses/{address}', [CustomerController::class, 'destroyAddress'])
        ->middleware('permission:customers.edit')
        ->name('customers.addresses.destroy');
    Route::post('/app/customers/{customer}/contacts', [CustomerController::class, 'storeContact'])
        ->middleware('permission:customers.edit')
        ->name('customers.contacts.store');
    Route::delete('/app/customers/{customer}/contacts/{contact}', [CustomerController::class, 'destroyContact'])
        ->middleware('permission:customers.edit')
        ->name('customers.contacts.destroy');
    Route::post('/app/customers/{customer}/feedback', [CustomerController::class, 'storeFeedback'])
        ->middleware('permission:customers.feedback')
        ->name('customers.feedback.store');
    Route::post('/app/customers/{customer}/referrals', [CustomerController::class, 'storeReferral'])
        ->middleware('permission:customers.referrals')
        ->name('customers.referrals.store');
    Route::post('/app/customers/{customer}/wishlist', [CustomerController::class, 'storeWishlist'])
        ->middleware('permission:customers.edit')
        ->name('customers.wishlist.store');
    Route::delete('/app/customers/{customer}/wishlist/{wishlist}', [CustomerController::class, 'destroyWishlist'])
        ->middleware('permission:customers.edit')
        ->name('customers.wishlist.destroy');

    /* ---- Suppliers (§06) ---- */
    Route::middleware('permission:suppliers.view')->group(function () {
        Route::get('/app/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        // The account list is registered before the {supplier} binding so the
        // literal path is never mistaken for a supplier id.
        Route::get('/app/suppliers/ledger', [SupplierController::class, 'ledgerIndex'])->name('suppliers.ledger.index');
    });
    Route::get('/app/suppliers/create', [SupplierController::class, 'create'])
        ->middleware('permission:suppliers.create')
        ->name('suppliers.create');
    Route::get('/app/suppliers/{supplier}/edit', [SupplierController::class, 'edit'])
        ->middleware('permission:suppliers.edit')
        ->name('suppliers.edit');
    Route::middleware('permission:suppliers.view')->group(function () {
        Route::get('/app/suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
        Route::get('/app/suppliers/{supplier}/ledger', [SupplierController::class, 'ledger'])->name('suppliers.ledger');
        Route::get('/app/suppliers/{supplier}/statement', [SupplierController::class, 'statement'])->name('suppliers.statement');
    });
    Route::post('/app/suppliers', [SupplierController::class, 'store'])
        ->middleware('permission:suppliers.create')
        ->name('suppliers.store');
    Route::put('/app/suppliers/{supplier}', [SupplierController::class, 'update'])
        ->middleware('permission:suppliers.edit')
        ->name('suppliers.update');
    Route::post('/app/suppliers/{supplier}/blacklist', [SupplierController::class, 'blacklist'])
        ->middleware('permission:suppliers.blacklist')
        ->name('suppliers.blacklist');
    Route::post('/app/suppliers/{supplier}/reinstate', [SupplierController::class, 'unblacklist'])
        ->middleware('permission:suppliers.blacklist')
        ->name('suppliers.reinstate');

    /* ---- Purchase orders (§03) ---- */
    Route::middleware('permission:purchase.orders.view')->group(function () {
        Route::get('/app/purchase/orders', [PurchaseOrderController::class, 'index'])->name('purchase.orders.index');
    });
    Route::get('/app/purchase/orders/create', [PurchaseOrderController::class, 'create'])
        ->middleware('permission:purchase.orders.create')
        ->name('purchase.orders.create');
    Route::post('/app/purchase/orders', [PurchaseOrderController::class, 'store'])
        ->middleware('permission:purchase.orders.create')
        ->name('purchase.orders.store');
    Route::middleware('permission:purchase.orders.view')->group(function () {
        Route::get('/app/purchase/orders/{order}', [PurchaseOrderController::class, 'show'])->name('purchase.orders.show');
    });
    Route::post('/app/purchase/orders/{order}/submit', [PurchaseOrderController::class, 'submit'])
        ->middleware('permission:purchase.orders.create')
        ->name('purchase.orders.submit');
    Route::post('/app/purchase/orders/{order}/approve', [PurchaseOrderController::class, 'approve'])
        ->middleware('permission:purchase.orders.approve')
        ->name('purchase.orders.approve');
    Route::post('/app/purchase/orders/{order}/cancel', [PurchaseOrderController::class, 'cancel'])
        ->middleware('permission:purchase.orders.cancel')
        ->name('purchase.orders.cancel');

    /* ---- Goods receipts (§03) ---- */
    Route::middleware('permission:purchase.receipts.view')->group(function () {
        Route::get('/app/purchase/receipts', [GoodsReceiptController::class, 'index'])->name('purchase.receipts.index');
    });
    Route::get('/app/purchase/receipts/create', [GoodsReceiptController::class, 'create'])
        ->middleware('permission:purchase.receipts.create')
        ->name('purchase.receipts.create');
    Route::post('/app/purchase/receipts', [GoodsReceiptController::class, 'store'])
        ->middleware('permission:purchase.receipts.create')
        ->name('purchase.receipts.store');
    Route::middleware('permission:purchase.receipts.view')->group(function () {
        Route::get('/app/purchase/receipts/{receipt}', [GoodsReceiptController::class, 'show'])->name('purchase.receipts.show');
    });
    Route::post('/app/purchase/receipts/{receipt}/post', [GoodsReceiptController::class, 'post'])
        ->middleware('permission:purchase.receipts.post')
        ->name('purchase.receipts.post');
    Route::post('/app/purchase/receipts/{receipt}/cancel', [GoodsReceiptController::class, 'cancel'])
        ->middleware('permission:purchase.receipts.cancel')
        ->name('purchase.receipts.cancel');

    /* ---- Purchase bills (§03.6) — the payable, and its posting to the GL ---- */
    Route::middleware('permission:purchase.bills.view')->group(function () {
        Route::get('/app/purchase/bills', [PurchaseBillController::class, 'index'])->name('purchase.bills.index');
    });
    Route::get('/app/purchase/payables', [PurchaseBillController::class, 'payables'])
        ->middleware('permission:purchase.bills.view')
        ->name('purchase.payables');
    Route::get('/app/purchase/bills/create', [PurchaseBillController::class, 'create'])
        ->middleware('permission:purchase.bills.create')
        ->name('purchase.bills.create');
    Route::post('/app/purchase/bills', [PurchaseBillController::class, 'store'])
        ->middleware('permission:purchase.bills.create')
        ->name('purchase.bills.store');
    Route::middleware('permission:purchase.bills.view')->group(function () {
        Route::get('/app/purchase/bills/{bill}', [PurchaseBillController::class, 'show'])->name('purchase.bills.show');
    });
    Route::post('/app/purchase/bills/{bill}/submit', [PurchaseBillController::class, 'submit'])
        ->middleware('permission:purchase.bills.create')
        ->name('purchase.bills.submit');
    // Approval is the moment the liability exists: its own permission, and the
    // maker can never approve their own bill (enforced in the service).
    Route::post('/app/purchase/bills/{bill}/approve', [PurchaseBillController::class, 'approve'])
        ->middleware('permission:purchase.bills.approve')
        ->name('purchase.bills.approve');
    Route::post('/app/purchase/bills/{bill}/cancel', [PurchaseBillController::class, 'cancel'])
        ->middleware('permission:purchase.bills.cancel')
        ->name('purchase.bills.cancel');

    /* ---- Supplier payments (§03.7) — money out against the payable ---- */
    Route::middleware('permission:purchase.payments.view')->group(function () {
        Route::get('/app/purchase/payments', [SupplierPaymentController::class, 'index'])->name('purchase.payments.index');
    });
    Route::get('/app/purchase/payments/create', [SupplierPaymentController::class, 'create'])
        ->middleware('permission:purchase.payments.create')
        ->name('purchase.payments.create');
    Route::post('/app/purchase/payments', [SupplierPaymentController::class, 'store'])
        ->middleware('permission:purchase.payments.create')
        ->name('purchase.payments.store');

    /* ---- Purchase returns (§03.9) — the correction path for a posted
       receipt or bill. `create` is registered before the {return} binding so
       the literal path is never mistaken for a document id. ---- */
    Route::get('/app/purchase/returns/create', [PurchaseReturnController::class, 'create'])
        ->middleware('permission:purchase.returns.create')
        ->name('purchase.returns.create');
    Route::post('/app/purchase/returns', [PurchaseReturnController::class, 'store'])
        ->middleware('permission:purchase.returns.create')
        ->name('purchase.returns.store');
    Route::middleware('permission:purchase.returns.view')->group(function () {
        Route::get('/app/purchase/returns', [PurchaseReturnController::class, 'index'])->name('purchase.returns.index');
        Route::get('/app/purchase/returns/{return}', [PurchaseReturnController::class, 'show'])->name('purchase.returns.show');
    });
    Route::post('/app/purchase/returns/{return}/submit', [PurchaseReturnController::class, 'submit'])
        ->middleware('permission:purchase.returns.create')
        ->name('purchase.returns.submit');
    Route::post('/app/purchase/returns/{return}/approve', [PurchaseReturnController::class, 'approve'])
        ->middleware('permission:purchase.returns.approve')
        ->name('purchase.returns.approve');
    Route::post('/app/purchase/returns/{return}/cancel', [PurchaseReturnController::class, 'cancel'])
        ->middleware('permission:purchase.returns.cancel')
        ->name('purchase.returns.cancel');

    /* ---- Masters (§14) — permission keys come from MasterCatalog ---- */
    foreach (MasterCatalog::all() as $slug => $entry) {
        $viewPermission = $entry['permission'];
        $mutatePermission = $entry['mutation_permission'] ?? $viewPermission;

        Route::get('/app/masters/'.$slug, [MasterDataController::class, 'index'])
            ->defaults('type', $slug)
            ->middleware('permission:'.$viewPermission)
            ->name('masters.'.$slug.'.index');
        Route::get('/app/masters/'.$slug.'/create', [MasterDataController::class, 'create'])
            ->defaults('type', $slug)
            ->middleware('permission:'.$mutatePermission)
            ->name('masters.'.$slug.'.create');
        Route::post('/app/masters/'.$slug, [MasterDataController::class, 'store'])
            ->defaults('type', $slug)
            ->middleware('permission:'.$mutatePermission)
            ->name('masters.'.$slug.'.store');
        Route::get('/app/masters/'.$slug.'/{record}/edit', [MasterDataController::class, 'edit'])
            ->defaults('type', $slug)
            ->middleware('permission:'.$mutatePermission)
            ->name('masters.'.$slug.'.edit');
        Route::put('/app/masters/'.$slug.'/{record}', [MasterDataController::class, 'update'])
            ->defaults('type', $slug)
            ->middleware('permission:'.$mutatePermission)
            ->name('masters.'.$slug.'.update');
        Route::delete('/app/masters/'.$slug.'/{record}', [MasterDataController::class, 'destroy'])
            ->defaults('type', $slug)
            ->middleware('permission:'.$mutatePermission)
            ->name('masters.'.$slug.'.destroy');
    }
    Route::redirect('/app/masters', '/app/masters/units')->name('masters.index');

    /* ---- Price management (02-108) ---- */
    Route::get('/app/pricing/price-lists', [PriceListController::class, 'index'])
        ->middleware('permission:pricing.manage')
        ->name('pricing.price-lists.index');
    Route::get('/app/pricing/price-lists/create', [PriceListController::class, 'create'])
        ->middleware('permission:pricing.manage')
        ->name('pricing.price-lists.create');
    Route::post('/app/pricing/price-lists', [PriceListController::class, 'store'])
        ->middleware('permission:pricing.manage')
        ->name('pricing.price-lists.store');
    Route::get('/app/pricing/price-lists/{priceList}/edit', [PriceListController::class, 'edit'])
        ->middleware('permission:pricing.manage')
        ->name('pricing.price-lists.edit');
    Route::put('/app/pricing/price-lists/{priceList}', [PriceListController::class, 'update'])
        ->middleware('permission:pricing.manage')
        ->name('pricing.price-lists.update');
    Route::delete('/app/pricing/price-lists/{priceList}', [PriceListController::class, 'destroy'])
        ->middleware('permission:pricing.manage')
        ->name('pricing.price-lists.destroy');

    Route::get('/app/pricing/bulk-update', [BulkPriceUpdateController::class, 'index'])
        ->middleware('permission:pricing.bulk_update')
        ->name('pricing.bulk-update');
    Route::post('/app/pricing/bulk-update', [BulkPriceUpdateController::class, 'store'])
        ->middleware('permission:pricing.bulk_update')
        ->name('pricing.bulk-update.store');

    Route::get('/app/pricing/history', [PriceHistoryController::class, 'index'])
        ->middleware('permission:pricing.view')
        ->name('pricing.history');

    Route::get('/app/pricing/compare', [PriceCompareController::class, 'index'])
        ->middleware('permission:pricing.view')
        ->name('pricing.compare');

    Route::get('/app/pricing/rules', [PricingRuleController::class, 'index'])
        ->middleware('permission:pricing.rules')
        ->name('pricing.rules.index');
    Route::get('/app/pricing/rules/create', [PricingRuleController::class, 'create'])
        ->middleware('permission:pricing.rules')
        ->name('pricing.rules.create');
    Route::post('/app/pricing/rules', [PricingRuleController::class, 'store'])
        ->middleware('permission:pricing.rules')
        ->name('pricing.rules.store');
    Route::get('/app/pricing/rules/{rule}/edit', [PricingRuleController::class, 'edit'])
        ->middleware('permission:pricing.rules')
        ->name('pricing.rules.edit');
    Route::put('/app/pricing/rules/{rule}', [PricingRuleController::class, 'update'])
        ->middleware('permission:pricing.rules')
        ->name('pricing.rules.update');
    Route::delete('/app/pricing/rules/{rule}', [PricingRuleController::class, 'destroy'])
        ->middleware('permission:pricing.rules')
        ->name('pricing.rules.destroy');
    Route::patch('/app/pricing/rules/{rule}/status', [PricingRuleController::class, 'toggleStatus'])
        ->middleware('permission:pricing.rules')
        ->name('pricing.rules.status');
    Route::patch('/app/pricing/rules/{rule}/priority', [PricingRuleController::class, 'updatePriority'])
        ->middleware('permission:pricing.rules')
        ->name('pricing.rules.priority');
    Route::post('/app/pricing/customer-groups', [PricingRuleController::class, 'storeGroup'])
        ->middleware('permission:pricing.rules')
        ->name('pricing.customer-groups.store');
    Route::delete('/app/pricing/customer-groups/{group}', [PricingRuleController::class, 'destroyGroup'])
        ->middleware('permission:pricing.rules')
        ->name('pricing.customer-groups.destroy');

    /* ---- Generic approvals (engine-backed) ---- */
    Route::middleware('permission:approvals.view')->group(function () {
        Route::get('/app/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
        Route::get('/app/approvals/{approval}', [ApprovalController::class, 'show'])->name('approvals.show');
    });
    Route::post('/app/approvals/{approval}/approve', [ApprovalController::class, 'approve'])
        ->middleware('permission:approvals.decide')
        ->name('approvals.approve');
    Route::post('/app/approvals/{approval}/reject', [ApprovalController::class, 'reject'])
        ->middleware('permission:approvals.decide')
        ->name('approvals.reject');
    Route::post('/app/approvals/{approval}/return', [ApprovalController::class, 'returnForCorrection'])
        ->middleware('permission:approvals.decide')
        ->name('approvals.return');
    Route::post('/app/approvals/{approval}/cancel', [ApprovalController::class, 'cancel'])
        ->middleware('permission:approvals.cancel')
        ->name('approvals.cancel');
    Route::post('/app/approvals/{approval}/comment', [ApprovalController::class, 'comment'])
        ->middleware('permission:approvals.comment')
        ->name('approvals.comment');

    /* ---- Workflow definitions (generic engine admin) ---- */
    Route::get('/app/workflows/create', [WorkflowController::class, 'create'])
        ->middleware('permission:workflows.manage')
        ->name('workflows.create');
    Route::middleware('permission:workflows.view')->group(function () {
        Route::get('/app/workflows', [WorkflowController::class, 'index'])->name('workflows.index');
    });
    Route::post('/app/workflows', [WorkflowController::class, 'store'])
        ->middleware('permission:workflows.manage')
        ->name('workflows.store');
    Route::middleware('permission:workflows.view')->group(function () {
        Route::get('/app/workflows/{definition}', [WorkflowController::class, 'show'])->name('workflows.show');
    });
    Route::get('/app/workflows/{definition}/edit', [WorkflowController::class, 'edit'])
        ->middleware('permission:workflows.manage')
        ->name('workflows.edit');
    Route::put('/app/workflows/{definition}', [WorkflowController::class, 'update'])
        ->middleware('permission:workflows.manage')
        ->name('workflows.update');
    Route::post('/app/workflows/{definition}/toggle', [WorkflowController::class, 'toggle'])
        ->middleware('permission:workflows.manage')
        ->name('workflows.toggle');

    /* ---- Accounting core (Phase D — rows 09-03…09-10, 09-32) ---- */
    Route::get('/app/accounting/coa', [AccountController::class, 'tree'])
        ->middleware('permission:accounting.coa.view')
        ->name('accounting.coa');
    Route::get('/app/accounting/account-groups', [AccountController::class, 'groups'])
        ->middleware('permission:accounting.coa.view')
        ->name('accounting.account-groups');
    Route::get('/app/accounting/accounts/create', [AccountController::class, 'create'])
        ->middleware('permission:accounting.coa.manage')
        ->name('accounting.accounts.create');
    Route::post('/app/accounting/accounts', [AccountController::class, 'store'])
        ->middleware('permission:accounting.coa.manage')
        ->name('accounting.accounts.store');
    Route::get('/app/accounting/accounts/{account}/edit', [AccountController::class, 'edit'])
        ->middleware('permission:accounting.coa.manage')
        ->name('accounting.accounts.edit');
    Route::put('/app/accounting/accounts/{account}', [AccountController::class, 'update'])
        ->middleware('permission:accounting.coa.manage')
        ->name('accounting.accounts.update');
    Route::delete('/app/accounting/accounts/{account}', [AccountController::class, 'destroy'])
        ->middleware('permission:accounting.coa.manage')
        ->name('accounting.accounts.destroy');

    Route::get('/app/accounting/journals', [JournalController::class, 'index'])
        ->middleware('permission:accounting.journals.view')
        ->name('accounting.journals.index');
    Route::get('/app/accounting/journals/create', [JournalController::class, 'create'])
        ->middleware('permission:accounting.journals.create')
        ->name('accounting.journals.create');
    Route::post('/app/accounting/journals', [JournalController::class, 'store'])
        ->middleware('permission:accounting.journals.create')
        ->name('accounting.journals.store');
    Route::get('/app/accounting/journals/{journal}', [JournalController::class, 'show'])
        ->middleware('permission:accounting.journals.view')
        ->name('accounting.journals.show');
    Route::post('/app/accounting/journals/{journal}/reverse', [JournalController::class, 'reverse'])
        ->middleware('permission:accounting.journals.reverse')
        ->name('accounting.journals.reverse');
    Route::get('/app/accounting/ledger/{account}', [JournalController::class, 'ledger'])
        ->middleware('permission:accounting.journals.view')
        ->name('accounting.ledger');

    /* ---- Cash & bank (§08-01 … §08-07, §08-11) ----
     *
     * Money the ledger has to see, entered where it can be seen: which account it
     * moved through, which account it is against, and — because a bank statement
     * arrives days later and a drawer is counted at night — who told the system
     * and when. Reading a book is `bank.view`; each kind of movement has its own
     * key, so a cashier who may take money in does not thereby get to pay it out.
     */
    Route::get('/app/cash-bank', [CashBankController::class, 'index'])
        ->middleware('permission:cash.view')
        ->name('cash-bank.index');
    Route::get('/app/cash-bank/accounts', [CashBankController::class, 'accounts'])
        ->middleware('permission:bank.accounts')
        ->name('cash-bank.accounts');
    Route::post('/app/cash-bank/accounts', [CashBankController::class, 'storeAccount'])
        ->middleware('permission:bank.accounts')
        ->name('cash-bank.accounts.store');
    Route::get('/app/cash-bank/accounts/{account}/edit', [CashBankController::class, 'editAccount'])
        ->middleware('permission:bank.accounts')
        ->name('cash-bank.accounts.edit');
    Route::put('/app/cash-bank/accounts/{account}', [CashBankController::class, 'updateAccount'])
        ->middleware('permission:bank.accounts')
        ->name('cash-bank.accounts.update');
    Route::post('/app/cash-bank/accounts/{account}/close', [CashBankController::class, 'closeAccount'])
        ->middleware('permission:bank.accounts')
        ->name('cash-bank.accounts.close');
    Route::get('/app/cash-bank/books/{account}', [CashBankController::class, 'book'])
        ->middleware('permission:bank.view')
        ->name('cash-bank.book');
    // §08-08/09/12: proving the books against the institution's own statement.
    // Two hubs because a bank account and a mobile wallet are reconciled under
    // different keys — the instrument decides, and the route cannot carry it.
    Route::get('/app/cash-bank/reconciliations', [BankReconciliationController::class, 'index'])
        ->middleware('permission:bank.view')
        ->name('cash-bank.reconciliations');
    Route::get('/app/cash-bank/wallets', [BankReconciliationController::class, 'wallets'])
        ->middleware('permission:wallets.accounts')
        ->name('cash-bank.wallets');
    Route::get('/app/cash-bank/accounts/{account}/statement', [BankReconciliationController::class, 'statement'])
        ->middleware('permission:bank.view')
        ->name('cash-bank.statement');
    Route::get('/app/cash-bank/accounts/{account}/statement/template', [BankReconciliationController::class, 'statementTemplate'])
        ->middleware('permission:bank.view')
        ->name('cash-bank.statement.template');
    Route::post('/app/cash-bank/accounts/{account}/statement', [BankReconciliationController::class, 'importStatement'])
        ->middleware('permission:bank.reconcile')
        ->name('cash-bank.statement.import');
    Route::get('/app/cash-bank/accounts/{account}/reconcile', [BankReconciliationController::class, 'reconcile'])
        ->middleware('permission:bank.view')
        ->name('cash-bank.reconcile');
    Route::post('/app/cash-bank/accounts/{account}/reconcile', [BankReconciliationController::class, 'open'])
        ->middleware('permission:bank.reconcile')
        ->name('cash-bank.reconcile.open');
    Route::get('/app/cash-bank/wallets/{account}/statement', [BankReconciliationController::class, 'walletStatement'])
        ->middleware('permission:wallets.accounts')
        ->name('cash-bank.wallets.statement');
    Route::post('/app/cash-bank/wallets/{account}/statement', [BankReconciliationController::class, 'importWalletStatement'])
        ->middleware('permission:wallets.reconcile')
        ->name('cash-bank.wallets.statement.import');
    Route::get('/app/cash-bank/wallets/{account}/reconcile', [BankReconciliationController::class, 'walletReconcile'])
        ->middleware('permission:wallets.accounts')
        ->name('cash-bank.wallets.reconcile');
    Route::post('/app/cash-bank/wallets/{account}/reconcile', [BankReconciliationController::class, 'openWallet'])
        ->middleware('permission:wallets.reconcile')
        ->name('cash-bank.wallets.reconcile.open');
    // The document itself: the instrument lives on its account, so the module
    // floor is the route's and the instrument's own key is asserted in the
    // controller, where the account is known.
    Route::get('/app/cash-bank/reconciliations/{reconciliation}', [BankReconciliationController::class, 'show'])
        ->middleware('permission:cash.view')
        ->name('cash-bank.reconciliations.show');
    Route::post('/app/cash-bank/reconciliations/{reconciliation}/closing', [BankReconciliationController::class, 'restate'])
        ->middleware('permission:cash.view')
        ->name('cash-bank.reconciliations.restate');
    Route::post('/app/cash-bank/reconciliations/{reconciliation}/match', [BankReconciliationController::class, 'match'])
        ->middleware('permission:cash.view')
        ->name('cash-bank.reconciliations.match');
    Route::post('/app/cash-bank/reconciliations/{reconciliation}/unmatch', [BankReconciliationController::class, 'unmatch'])
        ->middleware('permission:cash.view')
        ->name('cash-bank.reconciliations.unmatch');
    Route::post('/app/cash-bank/reconciliations/{reconciliation}/sign-off', [BankReconciliationController::class, 'signOff'])
        ->middleware('permission:cash.view')
        ->name('cash-bank.reconciliations.sign-off');

    Route::get('/app/cash-bank/receipts', [CashBankController::class, 'receipts'])
        ->middleware('permission:cash.view')
        ->name('cash-bank.receipts');
    Route::post('/app/cash-bank/receipts', [CashBankController::class, 'storeReceipt'])
        ->middleware('permission:cash.receipts.create')
        ->name('cash-bank.receipts.store');
    Route::get('/app/cash-bank/payments', [CashBankController::class, 'payments'])
        ->middleware('permission:cash.view')
        ->name('cash-bank.payments');
    Route::post('/app/cash-bank/payments', [CashBankController::class, 'storePayment'])
        ->middleware('permission:cash.payments.create')
        ->name('cash-bank.payments.store');
    Route::get('/app/cash-bank/transfer', [CashBankController::class, 'transfer'])
        ->middleware('permission:cash.view')
        ->name('cash-bank.transfer');
    Route::post('/app/cash-bank/transfer', [CashBankController::class, 'storeTransfer'])
        ->middleware('permission:cash.transfers')
        ->name('cash-bank.transfer.store');

    /*
     * §08-13/14 — the cheque register. Reading it is `cheques.view`, writing a
     * cheque into it is `cheques.manage`, and saying the bank paid it —
     * the moment the ledger moves — is `cheques.clear`. A merchant who may
     * write the slip is not thereby the person who decides the money arrived,
     * which is the same separation the statement desk keeps between the person
     * who imports a statement and the person who signs it off.
     */
    Route::get('/app/cash-bank/cheques', [ChequeController::class, 'index'])
        ->middleware('permission:cheques.view')
        ->name('cash-bank.cheques');
    Route::post('/app/cash-bank/cheques', [ChequeController::class, 'store'])
        ->middleware('permission:cheques.manage')
        ->name('cash-bank.cheques.store');
    // Cheque Print lands here: a menu leaf cannot carry a cheque id, so it opens
    // the list of issued cheques to print from rather than a page that cannot open.
    Route::get('/app/cash-bank/cheques/{cheque}', [ChequeController::class, 'show'])
        ->middleware('permission:cheques.view')
        ->name('cash-bank.cheques.show');
    Route::post('/app/cash-bank/cheques/{cheque}/transition', [ChequeController::class, 'transition'])
        ->middleware('permission:cheques.clear')
        ->name('cash-bank.cheques.transition');
    Route::get('/app/cash-bank/cheques/{cheque}/print', [ChequeController::class, 'print'])
        ->middleware('permission:cheques.print')
        ->name('cash-bank.cheques.print');

    /*
     * §08-15…§08-18 — the expense desk. Recording an expense, deciding it and
     * configuring what a category books to are three jobs: `expenses.view` reads
     * the register, `expenses.create` records one, `expenses.approve` is the
     * signature that lets a large one reach the ledger, and `expenses.categories`
     * decides which accounts the whole report is built from. The maker-checker
     * rule lives in ExpenseService, not in a hidden button, so holding the
     * approval key does not let anybody approve their own expense.
     */
    Route::get('/app/cash-bank/expenses', [ExpenseController::class, 'index'])
        ->middleware('permission:expenses.view')
        ->name('cash-bank.expenses');
    Route::get('/app/cash-bank/expenses/create', [ExpenseController::class, 'create'])
        ->middleware('permission:expenses.create')
        ->name('cash-bank.expenses.create');
    Route::post('/app/cash-bank/expenses', [ExpenseController::class, 'store'])
        ->middleware('permission:expenses.create')
        ->name('cash-bank.expenses.store');
    /*
     * §08-19 — the expenses that come round again. Registered before the {expense}
     * route on purpose: "/expenses/recurring" is a screen, not an expense id.
     * Generating needs `expenses.create` as well as `expenses.recurring`, because
     * a generator that could record expenses its operator could not type would be
     * a way round the expense desk altogether.
     */
    Route::get('/app/cash-bank/expenses/recurring', [ExpenseController::class, 'recurring'])
        ->middleware('permission:expenses.recurring')
        ->name('cash-bank.expenses.recurring');
    Route::post('/app/cash-bank/expenses/recurring', [ExpenseController::class, 'storeRecurring'])
        ->middleware('permission:expenses.recurring')
        ->name('cash-bank.expenses.recurring.store');
    Route::put('/app/cash-bank/expenses/recurring/{schedule}', [ExpenseController::class, 'updateRecurring'])
        ->middleware('permission:expenses.recurring')
        ->name('cash-bank.expenses.recurring.update');
    Route::post('/app/cash-bank/expenses/recurring/{schedule}/toggle', [ExpenseController::class, 'toggleRecurring'])
        ->middleware('permission:expenses.recurring')
        ->name('cash-bank.expenses.recurring.toggle');
    Route::post('/app/cash-bank/expenses/recurring-run', [ExpenseController::class, 'runRecurring'])
        ->middleware('permission:expenses.recurring,expenses.create')
        ->name('cash-bank.expenses.recurring.run');

    Route::get('/app/cash-bank/expenses/{expense}', [ExpenseController::class, 'show'])
        ->middleware('permission:expenses.view')
        ->name('cash-bank.expenses.show');
    Route::post('/app/cash-bank/expenses/{expense}/decide', [ExpenseController::class, 'decide'])
        ->middleware('permission:expenses.approve')
        ->name('cash-bank.expenses.decide');
    Route::get('/app/cash-bank/expense-categories', [ExpenseController::class, 'categories'])
        ->middleware('permission:expenses.view')
        ->name('cash-bank.expense-categories');
    Route::post('/app/cash-bank/expense-categories', [ExpenseController::class, 'storeCategory'])
        ->middleware('permission:expenses.categories')
        ->name('cash-bank.expense-categories.store');
    Route::put('/app/cash-bank/expense-categories/{category}', [ExpenseController::class, 'updateCategory'])
        ->middleware('permission:expenses.categories')
        ->name('cash-bank.expense-categories.update');

    /*
     * §08-21 — the float in the drawer. Four jobs, four keys: reading a float and
     * paying a voucher out of it is the custodian's (`pettycash.spend`), putting
     * money back in is its own act (`pettycash.replenish`) because it changes the
     * ledger's cash position, deciding what a custodian had to ask for is a
     * second pair of eyes (`pettycash.approve`), and declaring the float itself
     * creates a chart-of-accounts leaf, so it stays with `pettycash.funds`.
     *
     * Nobody can approve their own request — PettyCashService refuses it — so
     * holding the approval key is not a way round the maker-checker rule.
     */
    Route::get('/app/cash-bank/petty-cash', [PettyCashController::class, 'index'])
        ->middleware('permission:pettycash.funds')
        ->name('cash-bank.petty-cash');
    Route::post('/app/cash-bank/petty-cash', [PettyCashController::class, 'storeFund'])
        ->middleware('permission:pettycash.funds')
        ->name('cash-bank.petty-cash.store');
    Route::put('/app/cash-bank/petty-cash/{fund}', [PettyCashController::class, 'updateFund'])
        ->middleware('permission:pettycash.funds')
        ->name('cash-bank.petty-cash.update');
    Route::post('/app/cash-bank/petty-cash/{fund}/close', [PettyCashController::class, 'closeFund'])
        ->middleware('permission:pettycash.funds')
        ->name('cash-bank.petty-cash.close');
    Route::get('/app/cash-bank/petty-cash/requests', [PettyCashController::class, 'requests'])
        ->middleware('permission:pettycash.spend')
        ->name('cash-bank.petty-cash.requests');
    Route::post('/app/cash-bank/petty-cash/requests', [PettyCashController::class, 'storeRequest'])
        ->middleware('permission:pettycash.spend')
        ->name('cash-bank.petty-cash.requests.store');
    Route::post('/app/cash-bank/petty-cash/requests/{pettyRequest}/decide', [PettyCashController::class, 'decideRequest'])
        ->middleware('permission:pettycash.approve')
        ->name('cash-bank.petty-cash.requests.decide');
    Route::get('/app/cash-bank/petty-cash/expenses', [PettyCashController::class, 'expenses'])
        ->middleware('permission:pettycash.spend')
        ->name('cash-bank.petty-cash.expenses');
    Route::post('/app/cash-bank/petty-cash/expenses', [PettyCashController::class, 'storeVoucher'])
        ->middleware('permission:pettycash.spend')
        ->name('cash-bank.petty-cash.expenses.store');
    Route::get('/app/cash-bank/petty-cash/replenishments', [PettyCashController::class, 'replenishments'])
        ->middleware('permission:pettycash.replenish')
        ->name('cash-bank.petty-cash.replenishments');
    Route::post('/app/cash-bank/petty-cash/replenishments', [PettyCashController::class, 'storeReplenishment'])
        ->middleware('permission:pettycash.replenish')
        ->name('cash-bank.petty-cash.replenishments.store');

    /*
     * §08-05 — counting the drawer. Reading a count is the module floor
     * (`cash.view`); counting one is `cash.counts`, and answering for a
     * difference at or above the company's tolerance is `cash.counts.approve`.
     * The split matters: the person who was holding the money must not be the
     * person who writes off what is missing from it, and the service refuses
     * that even for somebody holding both keys.
     */
    Route::get('/app/cash-bank/cash-counts', [CashCountController::class, 'index'])
        ->middleware('permission:cash.view')
        ->name('cash-bank.cash-counts');
    Route::post('/app/cash-bank/cash-counts', [CashCountController::class, 'store'])
        ->middleware('permission:cash.counts')
        ->name('cash-bank.cash-counts.store');
    Route::post('/app/cash-bank/cash-counts/{cashCount}/decide', [CashCountController::class, 'decide'])
        ->middleware('permission:cash.counts.approve')
        ->name('cash-bank.cash-counts.decide');
    Route::get('/app/cash-bank/cash-counts/{cashCount}', [CashCountController::class, 'show'])
        ->middleware('permission:cash.view')
        ->name('cash-bank.cash-counts.show');

    /*
     * §08-10 — the money a bank takes without asking. Recording a charge is
     * bookkeeping, because the bank has already taken it; writing the *rule* that
     * decides what will be charged automatically from now on is policy, and it is
     * behind its own key (`bank.charges.rules`) for exactly that reason. Both the
     * rule and the reversal of a charge are the same shape the rest of this module
     * keeps: a mistake is answered rather than erased.
     */
    Route::get('/app/cash-bank/bank-charges', [BankChargeController::class, 'index'])
        ->middleware('permission:bank.charges')
        ->name('cash-bank.bank-charges');
    Route::post('/app/cash-bank/bank-charges', [BankChargeController::class, 'storeCharge'])
        ->middleware('permission:bank.charges')
        ->name('cash-bank.bank-charges.store');
    Route::post('/app/cash-bank/bank-charges/run', [BankChargeController::class, 'runDue'])
        ->middleware('permission:bank.charges')
        ->name('cash-bank.bank-charges.run');
    Route::post('/app/cash-bank/bank-charges/{charge}/reverse', [BankChargeController::class, 'reverse'])
        ->middleware('permission:bank.charges')
        ->name('cash-bank.bank-charges.reverse');
    Route::post('/app/cash-bank/bank-charge-rules', [BankChargeController::class, 'storeRule'])
        ->middleware('permission:bank.charges.rules')
        ->name('cash-bank.bank-charge-rules.store');
    Route::put('/app/cash-bank/bank-charge-rules/{rule}', [BankChargeController::class, 'updateRule'])
        ->middleware('permission:bank.charges.rules')
        ->name('cash-bank.bank-charge-rules.update');
    Route::post('/app/cash-bank/bank-charge-rules/{rule}/toggle', [BankChargeController::class, 'toggleRule'])
        ->middleware('permission:bank.charges.rules')
        ->name('cash-bank.bank-charge-rules.toggle');

    /*
     * §08-20 and §08-22 — the cash and expense report family.
     *
     * Two keys, because the two audiences are different people: reading what the
     * company spent is a manager's (every figure is a posted expense), and the
     * cash book is the treasurer's — it opens the money accounts themselves and
     * says where every taka in them came from and went to. Both are read-only:
     * nothing on these five screens can move money, which is why they are keys of
     * their own rather than a corner of the desks that can.
     */
    Route::get('/app/reports/cash/expenses', [CashReportController::class, 'expenses'])
        ->middleware('permission:expenses.reports')
        ->name('expenses.reports.index');
    Route::get('/app/reports/cash/book', [CashReportController::class, 'book'])
        ->middleware('permission:cash.reports')
        ->name('cash.reports.book');
    Route::get('/app/reports/cash/bank-book', [CashReportController::class, 'bankBook'])
        ->middleware('permission:cash.reports')
        ->name('cash.reports.bank-book');
    Route::get('/app/reports/cash/flow', [CashReportController::class, 'flow'])
        ->middleware('permission:cash.reports')
        ->name('cash.reports.flow');
    Route::get('/app/reports/cash/sessions', [CashReportController::class, 'sessions'])
        ->middleware('permission:cash.reports')
        ->name('cash.reports.sessions');

    /*
     * §13 — the report centre.
     *
     * One page per family the catalogue names, and the index above them. The
     * families are registered in a loop off ReportRegistry, so a family that
     * exists in the catalogue but has no reports cannot appear: the registry
     * reads the router, and the hub prints the gap in words instead of a blank
     * page. Each family has its own key because the audiences are different
     * people — reading the sales reports is not reading the payroll — and the
     * floor key only lets a reader see the index, where every family is listed
     * with the key it needs rather than being hidden.
     */
    Route::get('/app/reports', [ReportCentreController::class, 'index'])
        ->middleware('permission:reports.view')
        ->name('reports.index');
    Route::get('/app/reports/custom', [ReportCentreController::class, 'custom'])
        ->middleware('permission:reports.custom')
        ->name('reports.custom');
    Route::get('/app/reports/scheduled', [ReportCentreController::class, 'scheduled'])
        ->middleware('permission:reports.scheduled')
        ->name('reports.scheduled');
    Route::post('/app/reports/scheduled', [ReportCentreController::class, 'storeSchedule'])
        ->middleware('permission:reports.scheduled')
        ->name('reports.scheduled.store');

    foreach (ReportRegistry::FAMILIES as $familySlug => $familyDefinition) {
        Route::get('/app/reports/'.$familySlug, [ReportCentreController::class, 'family'])
            ->middleware('permission:'.$familyDefinition['permission'])
            ->defaults('family', $familySlug)
            ->name('reports.'.$familySlug);
    }

    Route::get('/app/accounting/opening-trial-balance', [FinancialReportController::class, 'openingTrialBalance'])
        ->middleware('permission:accounting.reports.view')
        ->name('accounting.reports.opening-trial-balance');
    Route::get('/app/accounting/trial-balance', [FinancialReportController::class, 'trialBalance'])
        ->middleware('permission:accounting.reports.view')
        ->name('accounting.reports.trial-balance');
    Route::get('/app/accounting/reconcile', [FinancialReportController::class, 'reconcile'])
        ->middleware('permission:accounting.reports.view')
        ->name('accounting.reports.reconcile');
    Route::post('/app/accounting/rebuild-balances', [FinancialReportController::class, 'rebuildBalances'])
        ->middleware('permission:accounting.reports.view')
        ->name('accounting.reports.rebuild');

    /* ---- Inventory core (Phase E — rows 04-01…04-33) ---- */
    Route::get('/app/inventory/products', [ProductController::class, 'index'])
        ->middleware('permission:inventory.products.view')
        ->name('inventory.products.index');
    Route::get('/app/inventory/products/create', [ProductController::class, 'create'])
        ->middleware('permission:inventory.products.create')
        ->name('inventory.products.create');
    Route::post('/app/inventory/products', [ProductController::class, 'store'])
        ->middleware('permission:inventory.products.create')
        ->name('inventory.products.store');
    Route::get('/app/inventory/products/{product}/edit', [ProductController::class, 'edit'])
        ->middleware('permission:inventory.products.edit')
        ->name('inventory.products.edit');
    Route::put('/app/inventory/products/{product}', [ProductController::class, 'update'])
        ->middleware('permission:inventory.products.edit')
        ->name('inventory.products.update');
    Route::delete('/app/inventory/products/{product}', [ProductController::class, 'destroy'])
        ->middleware('permission:inventory.products.edit')
        ->name('inventory.products.destroy');

    Route::get('/app/inventory/stock', [InventoryController::class, 'overview'])
        ->middleware('permission:inventory.stock.view')
        ->name('inventory.stock');
    Route::get('/app/inventory/movements', [InventoryController::class, 'movements'])
        ->middleware('permission:inventory.ledger.view')
        ->name('inventory.movements');
    // §04-12 — the catalogue as CSV, in and out. The import screen and its
    // history sit behind one key; reading the catalogue out has its own, because
    // taking a copy of the catalogue is a different permission from changing it.
    Route::get('/app/inventory/products/import', [ProductImportController::class, 'create'])
        ->middleware('permission:inventory.products.import')
        ->name('inventory.products.import');
    Route::post('/app/inventory/products/import', [ProductImportController::class, 'store'])
        ->middleware('permission:inventory.products.import')
        ->name('inventory.products.import.store');
    Route::get('/app/inventory/products/import/template', [ProductImportController::class, 'template'])
        ->middleware('permission:inventory.products.import')
        ->name('inventory.products.import.template');
    Route::get('/app/inventory/products/import/history', [ProductImportController::class, 'history'])
        ->middleware('permission:inventory.products.import')
        ->name('inventory.products.import.history');
    Route::get('/app/inventory/products/import/{import}', [ProductImportController::class, 'show'])
        ->middleware('permission:inventory.products.import')
        ->name('inventory.products.import.show');
    Route::get('/app/inventory/products/export', [ProductController::class, 'export'])
        ->middleware('permission:inventory.products.export')
        ->name('inventory.products.export');

    /*
     * §04-13/04-52/04-53/04-54 — barcodes, QR codes and labels.
     *
     * The generated sheet is served from the file that was stored, not re-rendered:
     * what the printer receives has to be the bytes whose checksum was recorded,
     * or the checksum means nothing. The two per-product endpoints return SVG, so
     * a product screen can show its own barcode with an <img> and the browser can
     * save it; they take the print key rather than the view key because a barcode
     * is a thing you print.
     */
    Route::get('/app/inventory/labels', [InventoryLabelController::class, 'index'])
        ->middleware('permission:inventory.labels')
        ->name('inventory.labels.index');
    Route::post('/app/inventory/labels/generate', [InventoryLabelController::class, 'generate'])
        ->middleware('permission:inventory.labels')
        ->name('inventory.labels.generate');
    Route::get('/app/inventory/labels/barcodes', [InventoryLabelController::class, 'barcodes'])
        ->middleware('permission:inventory.labels')
        ->name('inventory.labels.barcodes');
    Route::get('/app/inventory/labels/qr-codes', [InventoryLabelController::class, 'qrCodes'])
        ->middleware('permission:inventory.labels')
        ->name('inventory.labels.qr-codes');
    Route::get('/app/inventory/labels/scanner', [InventoryLabelController::class, 'scanner'])
        ->middleware('permission:inventory.labels')
        ->name('inventory.labels.scanner');
    Route::get('/app/inventory/labels/lookup', [InventoryLabelController::class, 'lookup'])
        ->middleware('permission:inventory.labels')
        ->name('inventory.labels.lookup');
    Route::get('/app/inventory/labels/sheets/{labelSheet}', [InventoryLabelController::class, 'sheet'])
        ->middleware('permission:inventory.labels')
        ->name('inventory.labels.sheet');
    Route::post('/app/inventory/labels/sheets/{labelSheet}/print', [InventoryLabelController::class, 'print'])
        ->middleware('permission:inventory.labels')
        ->name('inventory.labels.print');
    Route::get('/app/inventory/products/{product}/barcode.svg', [InventoryLabelController::class, 'productBarcode'])
        ->middleware('permission:inventory.products.print')
        ->name('inventory.products.barcode');
    Route::get('/app/inventory/products/{product}/qr.svg', [InventoryLabelController::class, 'productQr'])
        ->middleware('permission:inventory.products.print')
        ->name('inventory.products.qr');

    // §04-04 — a copy of the catalogue row, never a copy of the stock.
    Route::get('/app/inventory/products/{product}/duplicate', [ProductController::class, 'duplicateForm'])
        ->middleware('permission:inventory.products.create')
        ->name('inventory.products.duplicate.form');
    Route::post('/app/inventory/products/{product}/duplicate', [ProductController::class, 'duplicate'])
        ->middleware('permission:inventory.products.create')
        ->name('inventory.products.duplicate');
    // §04-10 — what the product record said it cost, and who changed it.
    Route::get('/app/inventory/cost-history', [ProductCostHistoryController::class, 'index'])
        ->middleware('permission:inventory.products.view')
        ->name('inventory.cost-history');
    Route::get('/app/inventory/products/{product}/cost-history', [ProductCostHistoryController::class, 'forProduct'])
        ->middleware('permission:inventory.products.view')
        ->name('inventory.products.cost-history');
    // §04-14 — the same append-only price history, scoped to one product.
    Route::get('/app/inventory/products/{product}/price-history', [PriceHistoryController::class, 'forProduct'])
        ->middleware('permission:pricing.view')
        ->name('inventory.products.price-history');

    Route::get('/app/inventory/products/{product}/ledger', [InventoryController::class, 'productLedger'])
        ->middleware('permission:inventory.ledger.view')
        ->name('inventory.products.ledger');
    Route::post('/app/inventory/rebuild-balances', [InventoryController::class, 'rebuildBalances'])
        ->middleware('permission:inventory.stock.view')
        ->name('inventory.stock.rebuild');

    Route::get('/app/inventory/stock/alerts', [InventoryController::class, 'alerts'])
        ->middleware('permission:inventory.reorder.view')
        ->name('inventory.stock.alerts');

    Route::get('/app/inventory/reorder-levels', [InventoryController::class, 'reorderLevels'])
        ->middleware('permission:inventory.reorder.view')
        ->name('inventory.reorder.index');

    /* ---- §04-55/56/58: the reorder desk, its proposals and their history.
           Reading the desk is the view key it always was; writing a proposal
           down and turning one into a purchase order needs the suggest key —
           and drafting an order needs the purchase module's create key too, so
           a key that only watched the shelves cannot raise paperwork with it.
           The history is a GET with a literal URI before the wildcard. ---- */
    Route::get('/app/inventory/reorder-suggestions', [ReorderSuggestionController::class, 'index'])
        ->middleware('permission:inventory.reorder.view')
        ->name('inventory.reorder.suggestions.index');
    Route::get('/app/inventory/reorder-history', [ReorderSuggestionController::class, 'history'])
        ->middleware('permission:inventory.reorder.view')
        ->name('inventory.reorder.history');
    Route::post('/app/inventory/reorder-suggestions', [ReorderSuggestionController::class, 'store'])
        ->middleware('permission:inventory.reorder.suggest')
        ->name('inventory.reorder.suggestions.store');
    Route::post('/app/inventory/reorder-suggestions/{suggestion}/accept', [ReorderSuggestionController::class, 'accept'])
        ->middleware('permission:inventory.reorder.suggest,purchase.orders.create')
        ->name('inventory.reorder.suggestions.accept');
    Route::post('/app/inventory/reorder-suggestions/{suggestion}/dismiss', [ReorderSuggestionController::class, 'dismiss'])
        ->middleware('permission:inventory.reorder.suggest')
        ->name('inventory.reorder.suggestions.dismiss');
    Route::post('/app/inventory/reorder-levels', [InventoryController::class, 'storeReorderLevel'])
        ->middleware('permission:inventory.reorder.configure')
        ->name('inventory.reorder.store');
    Route::delete('/app/inventory/reorder-levels/{policy}', [InventoryController::class, 'destroyReorderLevel'])
        ->middleware('permission:inventory.reorder.configure')
        ->name('inventory.reorder.destroy');

    /* ---- Damage & loss (§04-46…04-49) ---- */
    /*
     | §04-31 Stock count / cycle count. Opening a sheet freezes the numbers it
     | will ask about; writing counts down is the storekeeper's job; posting the
     | difference into stock needs `inventory.counts.post`, because that is the
     | moment the books change. A sheet that is still counting is the only one
     | that can be edited.
     */
    Route::get('/app/inventory/counts', [InventoryCountController::class, 'index'])
        ->middleware('permission:inventory.counts.view')
        ->name('inventory.counts.index');
    Route::get('/app/inventory/counts/create', [InventoryCountController::class, 'create'])
        ->middleware('permission:inventory.counts.create')
        ->name('inventory.counts.create');
    Route::post('/app/inventory/counts', [InventoryCountController::class, 'store'])
        ->middleware('permission:inventory.counts.create')
        ->name('inventory.counts.store');
    Route::get('/app/inventory/counts/{count}', [InventoryCountController::class, 'show'])
        ->middleware('permission:inventory.counts.view')
        ->name('inventory.counts.show');
    Route::post('/app/inventory/counts/{count}/counted', [InventoryCountController::class, 'saveCounts'])
        ->middleware('permission:inventory.counts.create')
        ->name('inventory.counts.save');
    Route::post('/app/inventory/counts/{count}/post', [InventoryCountController::class, 'postCount'])
        ->middleware('permission:inventory.counts.post')
        ->name('inventory.counts.post');
    Route::post('/app/inventory/counts/{count}/cancel', [InventoryCountController::class, 'cancel'])
        ->middleware('permission:inventory.counts.create')
        ->name('inventory.counts.cancel');

    Route::get('/app/inventory/damage', [InventoryDamageController::class, 'damage'])
        ->middleware('permission:inventory.stock.view')
        ->name('inventory.damage.index');
    Route::get('/app/inventory/damage/create', [InventoryDamageController::class, 'createDamage'])
        ->middleware('permission:inventory.damage.create')
        ->name('inventory.damage.create');
    Route::post('/app/inventory/damage', [InventoryDamageController::class, 'storeDamage'])
        ->middleware('permission:inventory.damage.create')
        ->name('inventory.damage.store');
    Route::post('/app/inventory/damage/{entry}/release', [InventoryDamageController::class, 'release'])
        ->middleware('permission:inventory.damage.create')
        ->name('inventory.damage.release');

    Route::get('/app/inventory/loss', [InventoryDamageController::class, 'losses'])
        ->middleware('permission:inventory.stock.view')
        ->name('inventory.loss.index');
    Route::get('/app/inventory/loss/create', [InventoryDamageController::class, 'createLoss'])
        ->middleware('permission:inventory.loss.create')
        ->name('inventory.loss.create');
    Route::post('/app/inventory/loss', [InventoryDamageController::class, 'storeLoss'])
        ->middleware('permission:inventory.loss.create')
        ->name('inventory.loss.store');

    Route::get('/app/inventory/writeoffs', [InventoryDamageController::class, 'writeoffs'])
        ->middleware('permission:inventory.stock.view')
        ->name('inventory.writeoffs.index');
    Route::get('/app/inventory/writeoffs/create', [InventoryDamageController::class, 'createWriteoff'])
        ->middleware('permission:inventory.writeoffs.create')
        ->name('inventory.writeoffs.create');
    Route::post('/app/inventory/writeoffs', [InventoryDamageController::class, 'storeWriteoff'])
        ->middleware('permission:inventory.writeoffs.create')
        ->name('inventory.writeoffs.store');
    Route::post('/app/inventory/writeoffs/{writeoff}/approve', [InventoryDamageController::class, 'approveWriteoff'])
        ->middleware('permission:inventory.writeoffs.approve')
        ->name('inventory.writeoffs.approve');
    Route::post('/app/inventory/writeoffs/{writeoff}/reject', [InventoryDamageController::class, 'rejectWriteoff'])
        ->middleware('permission:inventory.writeoffs.approve')
        ->name('inventory.writeoffs.reject');

    // Batches and expiry (§04-38/04-39) — the label on the goods, and the clock
    // that runs on it. Read-only apart from the one write that matters: a date
    // correction, which keeps its reason.
    Route::get('/app/inventory/batches', [InventoryBatchController::class, 'index'])
        ->middleware('permission:inventory.batch.view')
        ->name('inventory.batches.index');
    Route::get('/app/inventory/expiry', [InventoryBatchController::class, 'expiry'])
        ->middleware('permission:inventory.batch.view')
        ->name('inventory.batches.expiry');
    Route::post('/app/inventory/batches/{batch}/expiry', [InventoryBatchController::class, 'updateExpiry'])
        ->middleware('permission:inventory.batch.manage')
        ->name('inventory.batches.expiry.update');

    Route::get('/app/inventory/reservations', [InventoryReservationController::class, 'index'])
        ->middleware('permission:inventory.reservations.view')
        ->name('inventory.reservations.index');
    Route::post('/app/inventory/reservations/expire', [InventoryReservationController::class, 'expire'])
        ->middleware('permission:inventory.reservations.manage')
        ->name('inventory.reservations.expire');
    Route::post('/app/inventory/reservations/{reservation}/release', [InventoryReservationController::class, 'release'])
        ->middleware('permission:inventory.reservations.manage')
        ->name('inventory.reservations.release');

    // Stock report family (§04-35/04-36) — derived from the ledger, never cached.
    Route::get('/app/reports/inventory/aging', [InventoryReportController::class, 'aging'])
        ->middleware('permission:inventory.reports.view')
        ->name('inventory.reports.aging');
    Route::get('/app/reports/inventory/dead-stock', [InventoryReportController::class, 'deadStock'])
        ->middleware('permission:inventory.reports.view')
        ->name('inventory.reports.dead-stock');
    Route::get('/app/reports/inventory/stock', [InventoryReportController::class, 'stock'])
        ->middleware('permission:inventory.reports.view')
        ->name('inventory.reports.stock');
    Route::get('/app/reports/inventory/damage', [InventoryReportController::class, 'damage'])
        ->middleware('permission:inventory.reports.view')
        ->name('inventory.reports.damage');

    // §04-62: the packaging report joins the stock report family — same window,
    // same filters, same CSV, because it is the same kind of question.
    Route::get('/app/reports/inventory/packaging', [InventoryReportController::class, 'packaging'])
        ->middleware('permission:inventory.reports.view')
        ->name('inventory.reports.packaging');

    /* ---- §04-59/04-60/04-61: packaging as inventory, not just as a step in
           dispatch. Types are declared and retired here; the stock and cost
           screens read the same ledger and the same usage rows the sales desk
           writes. Reading the register is inventory.packaging, writing it is the
           manage key, and the two read-only screens keep the keys their figures
           belong to — stock to stock.view, cost to reports.view. ---- */
    Route::get('/app/inventory/packaging', [InventoryPackagingController::class, 'index'])
        ->middleware('permission:inventory.packaging')
        ->name('inventory.packaging.index');
    Route::get('/app/inventory/packaging/stock', [InventoryPackagingController::class, 'stock'])
        ->middleware('permission:inventory.stock.view')
        ->name('inventory.packaging.stock');
    Route::get('/app/inventory/packaging/cost', [InventoryPackagingController::class, 'cost'])
        ->middleware('permission:inventory.reports.view')
        ->name('inventory.packaging.cost');
    Route::post('/app/inventory/packaging/types', [InventoryPackagingController::class, 'store'])
        ->middleware('permission:inventory.packaging.manage')
        ->name('inventory.packaging.types.store');
    Route::put('/app/inventory/packaging/types/{packagingType}', [InventoryPackagingController::class, 'update'])
        ->middleware('permission:inventory.packaging.manage')
        ->name('inventory.packaging.types.update');
    Route::post('/app/inventory/packaging/types/{packagingType}/toggle', [InventoryPackagingController::class, 'toggle'])
        ->middleware('permission:inventory.packaging.manage')
        ->name('inventory.packaging.types.toggle');
    Route::delete('/app/inventory/packaging/types/{packagingType}', [InventoryPackagingController::class, 'destroy'])
        ->middleware('permission:inventory.packaging.manage')
        ->name('inventory.packaging.types.destroy');

    Route::get('/app/inventory/stock/opening', [InventoryController::class, 'createOpening'])
        ->middleware('permission:inventory.adjustments.create')
        ->name('inventory.stock.opening.create');
    Route::post('/app/inventory/stock/opening', [InventoryController::class, 'storeOpening'])
        ->middleware('permission:inventory.adjustments.create')
        ->name('inventory.stock.opening.store');

    Route::get('/app/inventory/adjustments', [InventoryController::class, 'adjustments'])
        ->middleware('permission:inventory.adjustments.view')
        ->name('inventory.adjustments.index');
    Route::get('/app/inventory/adjustments/create', [InventoryController::class, 'createAdjustment'])
        ->middleware('permission:inventory.adjustments.create')
        ->name('inventory.adjustments.create');
    Route::post('/app/inventory/adjustments', [InventoryController::class, 'storeAdjustment'])
        ->middleware('permission:inventory.adjustments.create')
        ->name('inventory.adjustments.store');
    /* §04-26: a holding pen for adjustments above the threshold, and the
       document's own history. Deciding one is its own permission. */
    Route::get('/app/inventory/adjustments/history', [InventoryController::class, 'adjustmentHistory'])
        ->middleware('permission:inventory.adjustments.view')
        ->name('inventory.adjustments.history');
    Route::post('/app/inventory/adjustments/{adjustment}/approve', [InventoryController::class, 'approveAdjustment'])
        ->middleware('permission:inventory.adjustments.approve')
        ->name('inventory.adjustments.approve');
    Route::post('/app/inventory/adjustments/{adjustment}/reject', [InventoryController::class, 'rejectAdjustment'])
        ->middleware('permission:inventory.adjustments.approve')
        ->name('inventory.adjustments.reject');

    Route::get('/app/inventory/transfers', [InventoryController::class, 'transfers'])
        ->middleware('permission:inventory.transfers.create')
        ->name('inventory.transfers.index');
    Route::get('/app/inventory/transfers/create', [InventoryController::class, 'createTransfer'])
        ->middleware('permission:inventory.transfers.create')
        ->name('inventory.transfers.create');
    Route::post('/app/inventory/transfers', [InventoryController::class, 'storeTransfer'])
        ->middleware('permission:inventory.transfers.create')
        ->name('inventory.transfers.store');
    /* §04-28: above the configured value a transfer waits here, and dispatch
       refuses it until somebody else approves — "in transit" then always means
       approved. Deciding one is its own permission. */
    Route::post('/app/inventory/transfers/{transfer}/approve', [InventoryController::class, 'approveTransfer'])
        ->middleware('permission:inventory.transfers.approve')
        ->name('inventory.transfers.approve');
    Route::post('/app/inventory/transfers/{transfer}/reject', [InventoryController::class, 'rejectTransfer'])
        ->middleware('permission:inventory.transfers.approve')
        ->name('inventory.transfers.reject');

    Route::post('/app/inventory/transfers/{transfer}/dispatch', [InventoryController::class, 'dispatchTransfer'])
        ->middleware('permission:inventory.transfers.dispatch')
        ->name('inventory.transfers.dispatch');
    Route::post('/app/inventory/transfers/{transfer}/receive', [InventoryController::class, 'receiveTransfer'])
        ->middleware('permission:inventory.transfers.receive')
        ->name('inventory.transfers.receive');

    /* ---- Sales core (Phase G — 02-01…02-70) ---- */
    Route::get('/app/sales/quotations', [SalesController::class, 'quotations'])
        ->middleware('permission:sales.quotations.view')
        ->name('sales.quotations.index');
    Route::post('/app/sales/quotations', [SalesController::class, 'storeQuotation'])
        ->middleware('permission:sales.quotations.create')
        ->name('sales.quotations.store');

    // 02-24…02-27: no permission middleware — SalesController::orders runs
    // a status-aware gate (sales.orders.view, or returns.view /
    // returns.refunds.view for the return-status filters) with the same
    // permission.denied audit as CheckPermission.
    Route::get('/app/sales/orders', [SalesController::class, 'orders'])
        ->name('sales.orders.index');
    Route::get('/app/sales/orders/export', [SalesController::class, 'exportOrders'])
        ->middleware('permission:sales.orders.export')
        ->name('sales.orders.export');
    Route::get('/app/sales/orders/{order}', [SalesController::class, 'showOrder'])
        ->middleware('permission:sales.orders.view')
        ->name('sales.orders.show');
    Route::post('/app/sales/orders', [SalesController::class, 'storeOrder'])
        ->middleware('permission:sales.orders.create')
        ->name('sales.orders.store');
    // Bulk actions must be registered before /orders/{order}/{action} or
    // "bulk" is captured as an order id by route-model binding.
    Route::post('/app/sales/orders/bulk/confirm', [SalesController::class, 'bulkConfirm'])
        ->middleware('permission:sales.orders.confirm')
        ->name('sales.orders.bulk.confirm');
    Route::post('/app/sales/orders/bulk/cancel', [SalesController::class, 'bulkCancel'])
        ->middleware('permission:sales.orders.cancel')
        ->name('sales.orders.bulk.cancel');
    Route::post('/app/sales/orders/bulk/assign-courier', [SalesController::class, 'bulkAssignCourier'])
        ->middleware('permission:sales.delivery.assign')
        ->name('sales.orders.bulk.assign-courier');
    Route::post('/app/sales/orders/bulk/print-invoice', [SalesController::class, 'bulkPrintInvoice'])
        ->middleware('permission:sales.invoices.print')
        ->name('sales.orders.bulk.print-invoice');
    Route::post('/app/sales/orders/bulk/print-packing-slip', [SalesController::class, 'bulkPrintPackingSlip'])
        ->middleware('permission:sales.orders.print')
        ->name('sales.orders.bulk.print-packing-slip');
    Route::post('/app/sales/orders/bulk/print-shipping-label', [SalesController::class, 'bulkPrintShippingLabel'])
        ->middleware('permission:sales.delivery.print')
        ->name('sales.orders.bulk.print-shipping-label');
    Route::post('/app/sales/orders/bulk/sms', [SalesController::class, 'bulkSms'])
        ->middleware('permission:sales.orders.notify')
        ->name('sales.orders.bulk.sms');
    Route::post('/app/sales/orders/bulk/whatsapp', [SalesController::class, 'bulkWhatsapp'])
        ->middleware('permission:sales.orders.notify')
        ->name('sales.orders.bulk.whatsapp');
    Route::post('/app/sales/orders/bulk/email', [SalesController::class, 'bulkEmail'])
        ->middleware('permission:sales.orders.notify')
        ->name('sales.orders.bulk.email');
    Route::get('/app/sales/orders/{order}/edit', [SalesController::class, 'editOrder'])
        ->middleware('permission:sales.orders.edit')
        ->name('sales.orders.edit');
    Route::put('/app/sales/orders/{order}', [SalesController::class, 'updateOrder'])
        ->middleware('permission:sales.orders.edit')
        ->name('sales.orders.update');
    Route::post('/app/sales/orders/{order}/confirm', [SalesController::class, 'confirmOrder'])
        ->middleware('permission:sales.orders.confirm')
        ->name('sales.orders.confirm');
    Route::post('/app/sales/orders/{order}/cancel', [SalesController::class, 'cancelOrder'])
        ->middleware('permission:sales.orders.cancel')
        ->name('sales.orders.cancel');
    Route::post('/app/sales/orders/{order}/suspicious-review', [SalesController::class, 'reviewSuspiciousOrder'])
        ->middleware('permission:sales.orders.view,sales.orders.review')
        ->name('sales.orders.suspicious-review');
    Route::post('/app/sales/orders/{order}/invoice', [SalesController::class, 'invoiceOrder'])
        ->middleware('permission:sales.invoices.create')
        ->name('sales.orders.invoice');

    Route::get('/app/sales/invoices', [SalesController::class, 'invoices'])
        ->middleware('permission:sales.invoices.view')
        ->name('sales.invoices.index');
    Route::get('/app/sales/invoices/{invoice}', [SalesController::class, 'showInvoice'])
        ->middleware('permission:sales.invoices.view')
        ->name('sales.invoices.show');
    Route::post('/app/sales/invoices/{invoice}/issue', [SalesController::class, 'issueInvoice'])
        ->middleware('permission:sales.invoices.issue')
        ->name('sales.invoices.issue');
    Route::get('/app/sales/invoices/{invoice}/mushak-9.1', [SalesController::class, 'mushak91'])
        ->middleware('permission:sales.invoices.statutory_print')
        ->name('sales.invoices.mushak-91');
    Route::post('/app/sales/payments', [SalesController::class, 'storePayment'])
        ->middleware('permission:sales.payments.create')
        ->name('sales.payments.store');

    Route::get('/app/sales/coupons', [SalesController::class, 'coupons'])
        ->middleware('permission:sales.coupons.view')
        ->name('sales.coupons.index');
    Route::post('/app/sales/coupons', [SalesController::class, 'storeCoupon'])
        ->middleware('permission:sales.coupons.create')
        ->name('sales.coupons.store');
    Route::get('/app/sales/coupons/usage', [SalesController::class, 'couponUsage'])
        ->middleware('permission:sales.coupons.view')
        ->name('sales.coupons.usage');
    Route::post('/app/sales/coupons/bulk-generate', [SalesController::class, 'bulkGenerateCoupons'])
        ->middleware('permission:sales.coupons.bulk')
        ->name('sales.coupons.bulk-generate');

    Route::get('/app/sales/promotions', [SalesController::class, 'promotions'])
        ->middleware('permission:sales.promotions.view')
        ->name('sales.promotions.index');
    Route::post('/app/sales/promotions', [SalesController::class, 'storePromotion'])
        ->middleware('permission:sales.promotions.create')
        ->name('sales.promotions.store');
    Route::get('/app/sales/promotions/flash', [SalesController::class, 'flashSales'])
        ->middleware('permission:sales.promotions.view')
        ->name('sales.promotions.flash');
    Route::get('/app/reports/sales/promotions', [SalesController::class, 'promotionReport'])
        ->middleware('permission:sales.reports.view')
        ->name('sales.reports.promotions');
    Route::get('/app/reports/sales/invoice-aging', [SalesController::class, 'invoiceAgingReport'])
        ->middleware('permission:sales.reports.view')
        ->name('sales.reports.invoice-aging');
    Route::get('/app/reports/sales/summary', [SalesController::class, 'salesSummaryReport'])
        ->middleware('permission:sales.reports.view')
        ->name('sales.reports.summary');
    Route::get('/app/reports/sales/peak-hours', [SalesController::class, 'peakHours'])
        ->middleware('permission:sales.reports.view')
        ->name('sales.reports.peak-hours');
    Route::get('/app/reports/sales/trend', [SalesController::class, 'salesTrend'])
        ->middleware('permission:sales.reports.view')
        ->name('sales.reports.trend');
    Route::get('/app/reports/sales/custom', [CustomReportController::class, 'index'])
        ->middleware('permission:sales.reports.view')
        ->name('sales.reports.custom');
    Route::post('/app/reports/sales/custom/run', [CustomReportController::class, 'run'])
        ->middleware('permission:sales.reports.view')
        ->name('sales.reports.custom.run');
    Route::post('/app/reports/sales/custom/definitions', [CustomReportController::class, 'storeDefinition'])
        ->middleware('permission:sales.reports.view')
        ->name('sales.reports.custom.definitions');
    Route::post('/app/reports/sales/custom/saved-filters', [CustomReportController::class, 'storeSavedFilter'])
        ->middleware('permission:sales.reports.view')
        ->name('sales.reports.custom.saved-filters');
    Route::post('/app/reports/sales/custom/schedules', [CustomReportController::class, 'storeSchedule'])
        ->middleware('permission:sales.reports.view')
        ->name('sales.reports.custom.schedules');
    Route::post('/app/reports/sales/custom/runs', [CustomReportController::class, 'triggerRun'])
        ->middleware('permission:sales.reports.view')
        ->name('sales.reports.custom.runs');
    foreach (['product', 'category', 'brand', 'customer', 'employee', 'branch', 'zone', 'method'] as $breakdownDim) {
        // 02-116: branch comparison additionally requires branches.compare.
        $breakdownPermission = $breakdownDim === 'branch'
            ? 'sales.reports.view,branches.compare'
            : 'sales.reports.view';

        Route::get('/app/reports/sales/by-'.$breakdownDim, [SalesController::class, 'salesBreakdown'])
            ->middleware('permission:'.$breakdownPermission)
            ->defaults('dim', $breakdownDim)
            ->name('sales.reports.by-'.$breakdownDim);
    }

    /* ---- Sales team (02-78…02-80) ---- */
    Route::get('/app/sales/team', [SalesController::class, 'team'])
        ->middleware('permission:sales.team.view')
        ->name('sales.team.index');
    Route::post('/app/sales/team', [SalesController::class, 'flagSalesPerson'])
        ->middleware('permission:sales.team.create')
        ->name('sales.team.store');
    Route::get('/app/sales/team/targets', [SalesController::class, 'teamTargets'])
        ->middleware('permission:sales.team.view')
        ->name('sales.team.targets.index');
    Route::post('/app/sales/team/targets', [SalesController::class, 'storeTarget'])
        ->middleware('permission:sales.team.targets')
        ->name('sales.team.targets.store');
    Route::get('/app/sales/team/achievement', [SalesController::class, 'teamAchievement'])
        ->middleware('permission:sales.team.view')
        ->name('sales.team.achievement');
    Route::get('/app/sales/team/leaderboard', [SalesController::class, 'teamLeaderboard'])
        ->middleware('permission:sales.team.view')
        ->name('sales.team.leaderboard');
    Route::get('/app/sales/team/performance', [SalesController::class, 'teamPerformance'])
        ->middleware('permission:sales.team.view')
        ->name('sales.team.performance');
    Route::get('/app/sales/team/commissions', [SalesController::class, 'teamCommissions'])
        ->middleware('permission:sales.team.commissions')
        ->name('sales.team.commissions.index');
    Route::post('/app/sales/team/commissions/calculate', [SalesController::class, 'calculateCommission'])
        ->middleware('permission:sales.team.commissions')
        ->name('sales.team.commissions.calculate');
    Route::post('/app/sales/team/commissions/{calculation}/pay', [SalesController::class, 'payCommission'])
        ->middleware('permission:sales.team.commission_pay')
        ->name('sales.team.commissions.pay');
    Route::get('/app/sales/team/commission-rules', [SalesController::class, 'commissionRules'])
        ->middleware('permission:sales.team.commissions')
        ->name('sales.team.commission-rules.index');
    Route::post('/app/sales/team/commission-rules', [SalesController::class, 'storeCommissionRule'])
        ->middleware('permission:sales.team.commissions')
        ->name('sales.team.commission-rules.store');

    /* ---- Sales field slice (02-84…02-87) ---- */
    Route::get('/app/sales/team/calls', [SalesController::class, 'teamCalls'])
        ->middleware('permission:sales.team.calls')
        ->name('sales.team.calls.index');
    Route::post('/app/sales/team/calls', [SalesController::class, 'storeCall'])
        ->middleware('permission:sales.team.calls')
        ->name('sales.team.calls.store');
    Route::get('/app/sales/team/field-sales', [SalesController::class, 'fieldSales'])
        ->middleware('permission:sales.team.view')
        ->name('sales.team.field-sales');
    Route::get('/app/sales/team/field-visits', [SalesController::class, 'fieldVisits'])
        ->middleware('permission:sales.team.field_tracking')
        ->name('sales.team.field-visits.index');
    Route::post('/app/sales/team/field-visits', [SalesController::class, 'storeFieldVisit'])
        ->middleware('permission:sales.team.field_tracking')
        ->name('sales.team.field-visits.store');
    Route::post('/app/sales/team/field-visits/{visit}/transition', [SalesController::class, 'transitionFieldVisit'])
        ->middleware('permission:sales.team.field_tracking')
        ->name('sales.team.field-visits.transition');
    Route::get('/app/sales/team/beat-plans', [SalesController::class, 'beatPlans'])
        ->middleware('permission:sales.team.field_tracking')
        ->name('sales.team.beat-plans.index');
    Route::post('/app/sales/team/beat-plans', [SalesController::class, 'storeBeatPlan'])
        ->middleware('permission:sales.team.field_tracking')
        ->name('sales.team.beat-plans.store');
    Route::get('/app/sales/team/territories', [SalesController::class, 'territories'])
        ->middleware('permission:sales.team.territories')
        ->name('sales.team.territories.index');
    Route::post('/app/sales/team/territories', [SalesController::class, 'storeTerritory'])
        ->middleware('permission:sales.team.territories')
        ->name('sales.team.territories.store');

    /* ---- Phase G remainders: revise/convert, delivery, returns ---- */
    Route::post('/app/sales/quotations/{quotation}/revise', [SalesController::class, 'reviseQuotation'])
        ->middleware('permission:sales.quotations.revise')
        ->name('sales.quotations.revise');
    Route::post('/app/sales/quotations/{quotation}/convert', [SalesController::class, 'convertQuotation'])
        ->middleware('permission:sales.quotations.convert')
        ->name('sales.quotations.convert');
    Route::post('/app/sales/quotations/{quotation}/accept', [SalesController::class, 'acceptQuotation'])
        ->middleware('permission:sales.quotations.process')
        ->name('sales.quotations.accept');
    Route::post('/app/sales/quotations/{quotation}/decline', [SalesController::class, 'declineQuotation'])
        ->middleware('permission:sales.quotations.process')
        ->name('sales.quotations.decline');
    Route::post('/app/sales/quotations/{quotation}/send', [SalesController::class, 'sendQuotation'])
        ->middleware('permission:sales.quotations.send')
        ->name('sales.quotations.send');
    Route::post('/app/sales/orders/{order}/challan', [SalesController::class, 'createChallan'])
        ->middleware('permission:sales.delivery.create')
        ->name('sales.orders.challan');
    Route::get('/app/sales/delivery-challans', [SalesController::class, 'deliveryChallans'])
        ->middleware('permission:sales.delivery.view')
        ->name('sales.delivery-challans.index');
    Route::get('/app/sales/delivery-challans/{challan}', [SalesController::class, 'showChallan'])
        ->middleware('permission:sales.delivery.view')
        ->name('sales.delivery-challans.show');
    Route::post('/app/sales/delivery-challans/{challan}/dispatch', [SalesController::class, 'dispatchChallan'])
        ->middleware('permission:sales.delivery.dispatch')
        ->name('sales.delivery-challans.dispatch');
    Route::post('/app/sales/delivery-challans/{challan}/deliver', [SalesController::class, 'deliverChallan'])
        ->middleware('permission:sales.delivery.dispatch')
        ->name('sales.delivery-challans.deliver');

    /* ---- Delivery zones & zone-wise charges (02-88) ---- */
    Route::get('/app/sales/delivery/zones', [DeliveryZoneController::class, 'index'])
        ->middleware('permission:sales.delivery.zones')
        ->name('sales.delivery.zones.index');
    Route::post('/app/sales/delivery/zones', [DeliveryZoneController::class, 'store'])
        ->middleware('permission:sales.delivery.zones')
        ->name('sales.delivery.zones.store');
    Route::post('/app/sales/delivery/zones/{zone}/charges', [ZoneChargeController::class, 'store'])
        ->middleware('permission:sales.delivery.zones')
        ->name('sales.delivery.zones.charges.store');
    Route::delete('/app/sales/delivery/zones/charges/{charge}', [ZoneChargeController::class, 'destroy'])
        ->middleware('permission:sales.delivery.zones')
        ->name('sales.delivery.zones.charges.destroy');

    /* ---- Shipments (02-89) ---- */
    Route::get('/app/sales/shipments', [ShipmentController::class, 'index'])
        ->middleware('permission:sales.delivery.shipments')
        ->name('sales.shipments.index');
    Route::post('/app/sales/shipments', [ShipmentController::class, 'store'])
        ->middleware('permission:sales.delivery.shipments.create')
        ->name('sales.shipments.store');
    Route::post('/app/sales/shipments/bulk', [ShipmentController::class, 'bulkStore'])
        ->middleware('permission:sales.delivery.shipments.create')
        ->name('sales.shipments.bulk');
    Route::post('/app/sales/shipments/{shipment}/dispatch', [ShipmentController::class, 'dispatch'])
        ->middleware('permission:sales.delivery.shipments.create')
        ->name('sales.shipments.dispatch');

    /* ---- Shipment tracking (02-90) ---- */
    Route::get('/app/sales/shipments/{shipment}/tracking', [TrackingEventController::class, 'show'])
        ->middleware('permission:sales.delivery.view')
        ->name('sales.shipments.tracking');
    Route::post('/app/sales/shipments/{shipment}/tracking', [TrackingEventController::class, 'store'])
        ->middleware('permission:sales.delivery.shipments.create')
        ->name('sales.shipments.tracking.store');

    /* ---- Proof of delivery (02-95) ---- */
    Route::get('/app/sales/delivery/pod', [ProofOfDeliveryController::class, 'index'])
        ->middleware('permission:sales.delivery.pod')
        ->name('sales.delivery.pod.index');
    Route::post('/app/sales/shipments/{shipment}/pod', [ProofOfDeliveryController::class, 'store'])
        ->middleware('permission:sales.delivery.pod')
        ->name('sales.delivery.pod.store');

    /* ---- Failed delivery management (02-96) ---- */
    Route::get('/app/sales/delivery/failed', [FailedDeliveryController::class, 'index'])
        ->middleware('permission:sales.delivery.view')
        ->name('sales.delivery.failed.index');
    Route::post('/app/sales/delivery/failed/{failedDelivery}/retry', [FailedDeliveryController::class, 'retry'])
        ->middleware('permission:sales.delivery.shipments.create')
        ->name('sales.delivery.failed.retry');
    Route::post('/app/sales/delivery/failed/{failedDelivery}/return', [FailedDeliveryController::class, 'returnGoods'])
        ->middleware('permission:sales.delivery.shipments.create')
        ->name('sales.delivery.failed.return');
    Route::post('/app/sales/delivery/failed/{failedDelivery}/reship', [FailedDeliveryController::class, 'reship'])
        ->middleware('permission:sales.delivery.shipments.create')
        ->name('sales.delivery.failed.reship');

    /* ---- Own delivery riders (02-93) ---- */
    Route::get('/app/sales/delivery/riders', [RiderController::class, 'index'])
        ->middleware('permission:sales.delivery.riders')
        ->name('sales.delivery.riders.index');
    Route::post('/app/sales/delivery/riders', [RiderController::class, 'store'])
        ->middleware('permission:sales.delivery.riders')
        ->name('sales.delivery.riders.store');
    Route::put('/app/sales/delivery/riders/{rider}', [RiderController::class, 'update'])
        ->middleware('permission:sales.delivery.riders')
        ->name('sales.delivery.riders.update');
    Route::get('/app/sales/delivery/riders/gps', [RiderController::class, 'gps'])
        ->middleware('permission:sales.delivery.riders')
        ->name('sales.delivery.riders.gps');
    // Rider-self or sales.delivery.riders holders; consent enforced server-side.
    Route::post('/app/sales/delivery/riders/{rider}/location', [RiderController::class, 'storeLocation'])
        ->name('sales.delivery.riders.location');

    Route::get('/app/sales/delivery/rider-assignments', [RiderAssignmentController::class, 'index'])
        ->middleware('permission:sales.delivery.riders')
        ->name('sales.delivery.rider-assignments.index');
    Route::post('/app/sales/delivery/rider-assignments', [RiderAssignmentController::class, 'store'])
        ->middleware('permission:sales.delivery.riders')
        ->name('sales.delivery.rider-assignments.store');
    Route::post('/app/sales/delivery/rider-assignments/{assignment}/accept', [RiderAssignmentController::class, 'accept'])
        ->name('sales.delivery.rider-assignments.accept');
    Route::post('/app/sales/delivery/rider-assignments/{assignment}/decline', [RiderAssignmentController::class, 'decline'])
        ->name('sales.delivery.rider-assignments.decline');

    Route::get('/app/sales/delivery/rider-cod', [RiderCodController::class, 'index'])
        ->middleware('permission:sales.delivery.riders')
        ->name('sales.delivery.rider-cod.index');
    Route::post('/app/sales/delivery/rider-cod', [RiderCodController::class, 'store'])
        ->middleware('permission:sales.delivery.riders')
        ->name('sales.delivery.rider-cod.store');

    /* ---- COD collection tracking & reconciliation (02-97) ---- */
    Route::get('/app/sales/delivery/cod', [CodController::class, 'index'])
        ->middleware('permission:sales.delivery.cod')
        ->name('sales.delivery.cod.index');
    Route::post('/app/sales/delivery/cod/reconcile', [CodController::class, 'reconcile'])
        ->middleware('permission:sales.delivery.cod.reconcile')
        ->name('sales.delivery.cod.reconcile');

    /* ---- Route optimization (02-94) ---- */
    Route::get('/app/sales/delivery/routes', [RouteOptimizerController::class, 'index'])
        ->middleware('permission:sales.delivery.routes')
        ->name('sales.delivery.routes.index');

    /* ---- Packaging management (02-98) ---- */
    Route::get('/app/sales/delivery/packaging', [PackagingController::class, 'index'])
        ->middleware('permission:sales.delivery.packaging')
        ->name('sales.delivery.packaging.index');
    Route::post('/app/sales/delivery/packaging', [PackagingController::class, 'store'])
        ->middleware('permission:sales.delivery.packaging')
        ->name('sales.delivery.packaging.store');
    Route::post('/app/sales/delivery/packaging/types', [PackagingController::class, 'storeType'])
        ->middleware('permission:sales.delivery.packaging')
        ->name('sales.delivery.packaging.types.store');

    Route::get('/app/sales/returns', [SalesController::class, 'returns'])
        ->middleware('permission:returns.view')
        ->name('sales.returns.index');
    Route::post('/app/sales/returns', [SalesController::class, 'storeReturn'])
        ->middleware('permission:returns.create')
        ->name('sales.returns.store');
    Route::post('/app/sales/returns/{salesReturn}/receive', [SalesController::class, 'receiveReturn'])
        ->middleware('permission:returns.receive')
        ->name('sales.returns.receive');
    Route::post('/app/sales/returns/{salesReturn}/credit', [SalesController::class, 'creditReturn'])
        ->middleware('permission:returns.credit')
        ->name('sales.returns.credit');
    Route::post('/app/sales/returns/{salesReturn}/refund', [SalesController::class, 'refundReturn'])
        ->middleware('permission:returns.refunds.create')
        ->name('sales.returns.refund');

    /* ---- POS (Phase G — 02-30…02-42) ---- */
    Route::get('/pos', [PosController::class, 'terminal'])
        ->middleware('permission:pos.sell')
        ->name('pos.terminal');
    Route::get('/pos/products', [PosController::class, 'products'])
        ->middleware('permission:pos.sell')
        ->name('pos.products');
    Route::get('/pos/price-check', [PosController::class, 'priceCheck'])
        ->middleware('permission:pos.price_check')
        ->name('pos.price-check');
    Route::post('/pos/sales', [PosController::class, 'commitSale'])
        ->middleware('permission:pos.sell')
        ->name('pos.sales.store');
    Route::get('/pos/receipt/{posTransaction}', [PosController::class, 'receipt'])
        ->middleware('permission:pos.sell')
        ->name('pos.receipt');
    Route::post('/pos/sync', [PosController::class, 'offlineSync'])
        ->middleware('permission:pos.offline')
        ->name('pos.sync');
    Route::post('/pos/hold', [PosController::class, 'storeHold'])
        ->middleware('permission:pos.hold')
        ->name('pos.hold.store');
    Route::get('/pos/holds', [PosController::class, 'holds'])
        ->middleware('permission:pos.hold')
        ->name('pos.holds.index');
    Route::post('/pos/holds/{hold}/resume', [PosController::class, 'resumeHold'])
        ->middleware('permission:pos.hold')
        ->name('pos.holds.resume');
    Route::get('/pos/sessions/{session}/x-report', [PosController::class, 'xReport'])
        ->middleware('permission:pos.reports.x')
        ->name('pos.sessions.x-report');
    Route::get('/pos/sessions/{session}/z-report', [PosController::class, 'zReport'])
        ->middleware('permission:pos.reports.z')
        ->name('pos.sessions.z-report');
    Route::get('/pos/sessions', [PosController::class, 'sessions'])
        ->middleware('permission:pos.sessions.view')
        ->name('pos.sessions.index');
    Route::post('/pos/sessions/open', [PosController::class, 'openSession'])
        ->middleware('permission:pos.sessions.open')
        ->name('pos.sessions.open');
    Route::post('/pos/sessions/{session}/close', [PosController::class, 'closeSession'])
        ->middleware('permission:pos.sessions.close')
        ->name('pos.sessions.close');
    Route::get('/pos/returns', [PosController::class, 'returns'])
        ->middleware('permission:pos.returns.create')
        ->name('pos.returns.index');
    Route::post('/pos/return', [PosController::class, 'returnSale'])
        ->middleware('permission:pos.returns.create')
        ->name('pos.return');
    Route::get('/pos/exchange', [PosController::class, 'exchange'])
        ->middleware('permission:pos.returns.exchange')
        ->name('pos.exchange.index');
    Route::post('/pos/exchange', [PosController::class, 'exchangeSale'])
        ->middleware('permission:pos.returns.exchange')
        ->name('pos.exchange');
    Route::get('/pos/drawer', [PosController::class, 'drawer'])
        ->middleware('permission:pos.cash_drawer')
        ->name('pos.drawer');
    Route::get('/pos/cash-in-out', [PosController::class, 'cashInOut'])
        ->middleware('permission:pos.cash_io')
        ->name('pos.cash-io.index');
    Route::post('/pos/cash-in-out', [PosController::class, 'storeCashInOut'])
        ->middleware('permission:pos.cash_io')
        ->name('pos.cash-io.store');
    Route::post('/pos/quotation', [PosController::class, 'quotationSale'])
        ->middleware('permission:sales.quotations.create')
        ->name('pos.quotation.store');
    Route::post('/pos/layaway', [PosController::class, 'layawaySale'])
        ->middleware('permission:pos.layaway')
        ->name('pos.layaway.store');
    Route::get('/pos/customer-display', [PosCustomerDisplayController::class, 'index'])
        ->middleware('permission:pos.customer_display')
        ->name('pos.customer-display.index');
    Route::get('/pos/customer-display/state', [PosCustomerDisplayController::class, 'state'])
        ->middleware('permission:pos.customer_display')
        ->name('pos.customer-display.state');
    Route::post('/pos/customer-display/push', [PosCustomerDisplayController::class, 'push'])
        ->middleware('permission:pos.customer_display')
        ->name('pos.customer-display.push');

    /* ---- §12 Business Management: the notice board (12-12) ----
     *
     * Reading the board is one key and writing is another, because everybody
     * reads notices and few write them. The register and the tracking page are
     * behind the writing key: they show drafts and gather names, which is a
     * publisher's view of the same rows.
     */
    Route::get('/app/notices', [NoticeController::class, 'index'])
        ->middleware('permission:business.notices.view')
        ->name('notices.index');
    Route::get('/app/notices/create', [NoticeController::class, 'create'])
        ->middleware('permission:business.notices.create')
        ->name('notices.create');
    Route::post('/app/notices', [NoticeController::class, 'store'])
        ->middleware('permission:business.notices.create')
        ->name('notices.store');
    Route::get('/app/notices/register', [NoticeController::class, 'register'])
        ->middleware('permission:business.notices.create')
        ->name('notices.register');
    Route::get('/app/notices/tracking', [NoticeController::class, 'tracking'])
        ->middleware('permission:business.notices.create')
        ->name('notices.tracking');
    // The literal segments above must stay ahead of this binding.
    Route::get('/app/notices/{notice}', [NoticeController::class, 'show'])
        ->middleware('permission:business.notices.view')
        ->name('notices.show');
    Route::post('/app/notices/{notice}/publish', [NoticeController::class, 'publish'])
        ->middleware('permission:business.notices.create')
        ->name('notices.publish');
    Route::post('/app/notices/{notice}/archive', [NoticeController::class, 'archive'])
        ->middleware('permission:business.notices.create')
        ->name('notices.archive');
    // Acknowledging is everybody's own act: it needs no key of its own beyond
    // being able to read the notice.
    Route::post('/app/notices/{notice}/acknowledge', [NoticeController::class, 'acknowledge'])
        ->middleware('permission:business.notices.view')
        ->name('notices.acknowledge');

    /* ---- §12 Business Management: tasks & projects (12-13) ----
     *
     * “My tasks” needs only tasks.view_own; “everybody's” needs tasks.view_all,
     * and the two are separate routes so the scope is decided by the door a
     * person came through rather than by a flag the view might forget. Moving a
     * card and commenting are open to the assignee — the state machine decides
     * what is legal — while assignment, due dates and creation are tasks.manage.
     */
    Route::get('/app/tasks', [TaskController::class, 'index'])
        ->middleware('permission:tasks.view_own')
        ->name('tasks.index');
    Route::get('/app/tasks/all', [TaskController::class, 'all'])
        ->middleware('permission:tasks.view_all')
        ->name('tasks.all');
    Route::get('/app/tasks/kanban', [TaskController::class, 'kanban'])
        ->middleware('permission:tasks.view_own')
        ->name('tasks.kanban');
    Route::get('/app/tasks/create', [TaskController::class, 'create'])
        ->middleware('permission:tasks.manage')
        ->name('tasks.create');
    Route::post('/app/tasks', [TaskController::class, 'store'])
        ->middleware('permission:tasks.manage')
        ->name('tasks.store');
    Route::get('/app/tasks/{task}', [TaskController::class, 'show'])
        ->middleware('permission:tasks.view_own')
        ->name('tasks.show');
    Route::put('/app/tasks/{task}', [TaskController::class, 'update'])
        ->middleware('permission:tasks.manage')
        ->name('tasks.update');
    Route::post('/app/tasks/{task}/assign', [TaskController::class, 'assign'])
        ->middleware('permission:tasks.manage')
        ->name('tasks.assign');
    Route::post('/app/tasks/{task}/due', [TaskController::class, 'reschedule'])
        ->middleware('permission:tasks.view_own')
        ->name('tasks.reschedule');
    Route::post('/app/tasks/{task}/status', [TaskController::class, 'transition'])
        ->middleware('permission:tasks.view_own')
        ->name('tasks.transition');
    Route::post('/app/tasks/{task}/comments', [TaskController::class, 'comment'])
        ->middleware('permission:tasks.view_own')
        ->name('tasks.comment');

    Route::get('/app/projects', [ProjectController::class, 'index'])
        ->middleware('permission:tasks.view_own')
        ->name('projects.index');
    Route::post('/app/projects', [ProjectController::class, 'store'])
        ->middleware('permission:tasks.manage')
        ->name('projects.store');
    Route::get('/app/projects/{project}', [ProjectController::class, 'show'])
        ->middleware('permission:tasks.view_own')
        ->name('projects.show');
    Route::put('/app/projects/{project}', [ProjectController::class, 'update'])
        ->middleware('permission:tasks.manage')
        ->name('projects.update');

    /* ---- §12 Business Management: meetings, minutes and action items (12-11) ----
     *
     * Reading is for anybody on the list — the diary of your own meetings, the
     * minutes of meetings that have been held, and the action items you owe.
     * Running the diary (calling one, moving it, cancelling it, minuting it,
     * raising work out of it) is `business.meetings.manage`.
     *
     * The literal segments come first: `/app/meetings/minutes` and
     * `/app/meetings/action-items` are pages, not meetings called “minutes”.
     */
    Route::middleware('permission:business.meetings.view')->group(function () {
        Route::get('/app/meetings', [MeetingController::class, 'index'])
            ->name('meetings.index');
        Route::get('/app/meetings/minutes', [MeetingController::class, 'minutes'])
            ->name('meetings.minutes');
        Route::get('/app/meetings/action-items', [MeetingController::class, 'actionItems'])
            ->name('meetings.actionItems');
        Route::get('/app/meetings/{meeting}', [MeetingController::class, 'show'])
            ->whereNumber('meeting')
            ->name('meetings.show');
        Route::post('/app/meetings/{meeting}/respond', [MeetingController::class, 'respond'])
            ->whereNumber('meeting')
            ->name('meetings.respond');
    });

    Route::middleware('permission:business.meetings.manage')->group(function () {
        Route::get('/app/meetings/create', [MeetingController::class, 'create'])
            ->name('meetings.create');
        Route::post('/app/meetings', [MeetingController::class, 'store'])
            ->name('meetings.store');
        Route::post('/app/meetings/{meeting}/hold', [MeetingController::class, 'hold'])
            ->whereNumber('meeting')
            ->name('meetings.hold');
        Route::post('/app/meetings/{meeting}/reschedule', [MeetingController::class, 'reschedule'])
            ->whereNumber('meeting')
            ->name('meetings.reschedule');
        Route::post('/app/meetings/{meeting}/cancel', [MeetingController::class, 'cancel'])
            ->whereNumber('meeting')
            ->name('meetings.cancel');
        Route::post('/app/meetings/{meeting}/attendance', [MeetingController::class, 'attendance'])
            ->whereNumber('meeting')
            ->name('meetings.attendance');
        Route::post('/app/meetings/{meeting}/minutes', [MeetingController::class, 'recordMinutes'])
            ->whereNumber('meeting')
            ->name('meetings.recordMinutes');
        Route::post('/app/meetings/{meeting}/attendees', [MeetingController::class, 'attendee'])
            ->whereNumber('meeting')
            ->name('meetings.attendee');
        Route::post('/app/meetings/{meeting}/action-items', [MeetingController::class, 'actionItem'])
            ->whereNumber('meeting')
            ->name('meetings.actionItem');
    });

    /* ---- §12 Business Management: the company's registers (12-03, 12-04, 12-09, 12-10) ----
     *
     * Nine kinds of record — trade licence, TIN & BIN, certificates, contracts,
     * agreements, brand assets, insurance, RJSC filings, statutory obligations —
     * behind one register. `/app/records/{kind}` takes a slug (`brand-asset`)
     * while `/app/records/{record}` takes an id, so the two cannot be confused;
     * the letters-only constraint on the first is what keeps them apart.
     */
    Route::middleware('permission:business.records.view')->group(function () {
        Route::get('/app/records', [BusinessRecordController::class, 'index'])
            ->name('records.index');
        Route::get('/app/records/{kind}', [BusinessRecordController::class, 'kind'])
            ->where('kind', '[a-z][a-z-]*')
            ->name('records.kind');
        Route::get('/app/records/{record}', [BusinessRecordController::class, 'show'])
            ->whereNumber('record')
            ->name('records.show');

        Route::get('/app/compliance/renewals', [BusinessRecordController::class, 'renewals'])
            ->name('compliance.renewals');
        Route::get('/app/compliance/calendar', [BusinessRecordController::class, 'calendar'])
            ->name('compliance.calendar');
        Route::get('/app/compliance/obligations', [BusinessRecordController::class, 'obligations'])
            ->name('compliance.obligations');
    });

    Route::middleware('permission:business.records.manage')->group(function () {
        Route::post('/app/records', [BusinessRecordController::class, 'store'])
            ->name('records.store');
        Route::put('/app/records/{record}', [BusinessRecordController::class, 'update'])
            ->whereNumber('record')
            ->name('records.update');
        Route::post('/app/records/{record}/renew', [BusinessRecordController::class, 'renew'])
            ->whereNumber('record')
            ->name('records.renew');
        Route::post('/app/records/{record}/complete', [BusinessRecordController::class, 'complete'])
            ->whereNumber('record')
            ->name('records.complete');
        Route::post('/app/records/{record}/attach', [BusinessRecordController::class, 'attach'])
            ->whereNumber('record')
            ->name('records.attach');
        Route::delete('/app/records/{record}/attach/{document}', [BusinessRecordController::class, 'detach'])
            ->whereNumber('record')
            ->name('records.detach');
        Route::post('/app/records/{record}/retire', [BusinessRecordController::class, 'retire'])
            ->whereNumber('record')
            ->name('records.retire');
    });

    /* ---- §12 Business Management: the asset register (12-14) ----
     *
     * One register, four screens: the register itself, the vehicles, the
     * equipment and the trip log. A vehicle is an asset with plates, so the
     * vehicle list is a lens on `business_assets` rather than a second table —
     * which is why nothing here can lose a truck.
     *
     * The literal segments come before `{asset}` and the binding is constrained
     * to digits, so `/app/assets/vehicles` is a shelf and `/app/assets/12` is an
     * asset, and neither can be mistaken for the other.
     *
     * Reading is `business.assets.view`: everybody needs to know where the laptop
     * is. Everything that touches the books — registering, moving, capitalising,
     * depreciating, writing off, logging trips — is `business.assets.manage`.
     */
    Route::middleware('permission:business.assets.view')->group(function () {
        Route::get('/app/assets', [AssetController::class, 'index'])->name('assets.index');
        Route::get('/app/assets/vehicles', [AssetController::class, 'vehicles'])->name('assets.vehicles');
        Route::get('/app/assets/equipment', [AssetController::class, 'equipment'])->name('assets.equipment');
        Route::get('/app/assets/trips', [AssetController::class, 'trips'])->name('assets.trips');
        Route::get('/app/assets/depreciation', [AssetController::class, 'depreciation'])->name('assets.depreciation');
        Route::get('/app/assets/disposal', [AssetController::class, 'disposal'])->name('assets.disposal');
        Route::get('/app/assets/{asset}', [AssetController::class, 'show'])
            ->whereNumber('asset')
            ->name('assets.show');
    });

    Route::middleware('permission:business.assets.manage')->group(function () {
        Route::get('/app/assets/create', [AssetController::class, 'create'])->name('assets.create');
        Route::post('/app/assets', [AssetController::class, 'store'])->name('assets.store');
        Route::post('/app/assets/trips', [AssetController::class, 'logTrip'])->name('assets.trips.log');
        Route::post('/app/assets/depreciation/run', [AssetController::class, 'runDepreciation'])
            ->name('assets.depreciation.run');
        Route::put('/app/assets/{asset}', [AssetController::class, 'update'])
            ->whereNumber('asset')
            ->name('assets.update');
        Route::post('/app/assets/{asset}/depreciation', [AssetController::class, 'setDepreciation'])
            ->whereNumber('asset')
            ->name('assets.depreciation.set');
        Route::post('/app/assets/{asset}/capitalise', [AssetController::class, 'capitalise'])
            ->whereNumber('asset')
            ->name('assets.capitalise');
        Route::post('/app/assets/{asset}/dispose', [AssetController::class, 'dispose'])
            ->whereNumber('asset')
            ->name('assets.dispose');
        Route::post('/app/assets/{asset}/records', [AssetController::class, 'linkRecord'])
            ->whereNumber('asset')
            ->name('assets.records.link');
        Route::delete('/app/assets/{asset}/records/{record}', [AssetController::class, 'unlinkRecord'])
            ->whereNumber('asset')
            ->whereNumber('record')
            ->name('assets.records.unlink');
    });

    /* ---- Audit trail ---- */
    Route::middleware('permission:audit.view')->group(function () {
        Route::get('/app/audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('/app/audit/{event}', [AuditController::class, 'show'])->name('audit.show');
    });
    Route::get('/app/audit-export/audit.csv', [AuditController::class, 'export'])
        ->middleware('permission:audit.export')
        ->name('audit.export');

    /* ---- Documents ---- */
    Route::middleware('permission:documents.view')->group(function () {
        Route::get('/app/documents', [DocumentController::class, 'index'])->name('documents.index');
    });
    Route::post('/app/documents', [DocumentController::class, 'store'])
        ->middleware('permission:documents.upload')
        ->name('documents.store');
    Route::get('/app/documents/{document}/download', [DocumentController::class, 'download'])
        ->middleware('permission:documents.download')
        ->name('documents.download');
    Route::delete('/app/documents/{document}', [DocumentController::class, 'destroy'])
        ->middleware('permission:documents.manage')
        ->name('documents.destroy');

    /* ---- Navigation registry admin ---- */
    Route::get('/app/navigation', [MenuController::class, 'index'])
        ->middleware('permission:menus.view')
        ->name('menus.index');
    Route::post('/app/navigation/{item}/status', [MenuController::class, 'toggleStatus'])
        ->middleware('permission:menus.manage')
        ->name('menus.toggle');
});
