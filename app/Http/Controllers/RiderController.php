<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\Actions\CreateRiderProfile;
use App\Domain\Delivery\RiderAssignment;
use App\Domain\Delivery\RiderProfile;
use App\Domain\Delivery\Services\RiderLocationService;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\People\Employee;
use App\Domain\Sales\GpsPoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Own delivery rider roster + GPS intake (02-93). Riders are
 * employees — the roster row carries vehicle/availability/consent
 * state. Location writes go through RiderLocationService (approved
 * sharing only). Rider-self may post their own position; everything
 * else needs sales.delivery.riders.
 */
class RiderController extends Controller
{
    public function index(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;

        $profiles = RiderProfile::query()
            ->where('company_id', $companyId)
            ->with('employee')
            ->orderByDesc('id')
            ->get();

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereNotIn('id', $profiles->pluck('employee_id'))
            ->orderBy('full_name')
            ->get();

        $pendingCounts = $profiles->isEmpty()
            ? collect()
            : RiderAssignment::query()
                ->where('company_id', $companyId)
                ->where('status', RiderAssignment::STATUS_PENDING)
                ->whereIn('rider_employee_id', $profiles->pluck('employee_id'))
                ->groupBy('rider_employee_id')
                ->selectRaw('rider_employee_id, COUNT(*) as pending_count')
                ->pluck('pending_count', 'rider_employee_id');

        return view('sales.delivery.riders', [
            'profiles' => $profiles,
            'employees' => $employees,
            'pendingCounts' => $pendingCounts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = (int) $request->user()->company_id;

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')
                ->where('company_id', $companyId)],
            'vehicle_type' => ['nullable', 'string', 'max:32'],
            'vehicle_plate' => ['nullable', 'string', 'max:32'],
            'is_available' => ['sometimes', 'boolean'],
            'gps_consent' => ['sometimes', 'boolean'],
        ]);

        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->findOrFail((int) $data['employee_id']);

        try {
            app(CreateRiderProfile::class)->handle($employee, [
                'vehicle_type' => $data['vehicle_type'] ?? null,
                'vehicle_plate' => $data['vehicle_plate'] ?? null,
                'is_available' => $request->boolean('is_available', true),
                'gps_consent' => $request->boolean('gps_consent'),
            ], $request);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return back()->withErrors(['employee_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.delivery.riders.index')
            ->with('status', sprintf('Rider %s added to the roster.', $employee->full_name));
    }

    public function update(Request $request, RiderProfile $rider): RedirectResponse
    {
        $companyId = (int) $request->user()->company_id;

        if ((int) $rider->company_id !== $companyId) {
            abort(404);
        }

        $data = $request->validate([
            'vehicle_type' => ['nullable', 'string', 'max:32'],
            'vehicle_plate' => ['nullable', 'string', 'max:32'],
            'is_available' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'gps_consent' => ['sometimes', 'boolean'],
        ]);

        $employee = $rider->employee()->firstOrFail();

        app(CreateRiderProfile::class)->handle($employee, [
            'vehicle_type' => $data['vehicle_type'] ?? $rider->vehicle_type,
            'vehicle_plate' => $data['vehicle_plate'] ?? $rider->vehicle_plate,
            'is_available' => $request->boolean('is_available', $rider->is_available),
            'is_active' => $request->boolean('is_active', $rider->is_active),
            'gps_consent' => $request->boolean('gps_consent', $rider->gps_consent),
        ], $request);

        return redirect()
            ->route('sales.delivery.riders.index')
            ->with('status', 'Rider profile updated.');
    }

    /** Dispatcher GPS view: latest consented position per roster rider. */
    public function gps(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;

        $profiles = RiderProfile::query()
            ->where('company_id', $companyId)
            ->with('employee')
            ->orderBy('id')
            ->get();

        $employeeIds = $profiles->pluck('employee_id')->values()->all();

        $latest = $employeeIds === []
            ? collect()
            : GpsPoint::query()
                ->where('company_id', $companyId)
                ->where('source', 'rider')
                ->whereIn('employee_id', $employeeIds)
                ->whereIn('id', GpsPoint::query()
                    ->selectRaw('MAX(id)')
                    ->where('company_id', $companyId)
                    ->where('source', 'rider')
                    ->whereIn('employee_id', $employeeIds)
                    ->groupBy('employee_id'))
                ->get()
                ->keyBy('employee_id');

        return view('sales.delivery.rider-gps', [
            'profiles' => $profiles,
            'latest' => $latest,
        ]);
    }

    /**
     * Record a rider position. Rider-self (own profile) or
     * sales.delivery.riders holders; consent enforced server-side.
     */
    public function storeLocation(Request $request, RiderProfile $rider): RedirectResponse
    {
        $companyId = (int) $request->user()->company_id;

        if ((int) $rider->company_id !== $companyId) {
            abort(404);
        }

        if (! $this->mayRecord($request, $rider)) {
            abort(403, 'You can only record your own rider location.');
        }

        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'captured_at' => ['nullable', 'date'],
        ]);

        try {
            app(RiderLocationService::class)->record($rider, $data, $request);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return back()->withErrors(['location' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.delivery.riders.gps')
            ->with('status', 'Rider location recorded.');
    }

    protected function mayRecord(Request $request, RiderProfile $rider): bool
    {
        $user = $request->user();

        if (app(PermissionCatalog::class)
            ->allows($user, 'sales.delivery.riders')) {
            return true;
        }

        return $rider->employee !== null
            && (int) $rider->employee->user_id === (int) $user->id;
    }
}
