<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\Actions\AssignRider;
use App\Domain\Delivery\Actions\RespondToRiderAssignment;
use App\Domain\Delivery\RiderAssignment;
use App\Domain\Delivery\RiderProfile;
use App\Domain\Delivery\Shipment;
use App\Domain\Masters\Courier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Rider assignment board (02-93): link roster riders to shipments,
 * respond accept/decline (rider-self or sales.delivery.riders
 * holders). Assignment itself has no stock/GL effect — handover
 * stays the dispatch step (02-89).
 */
class RiderAssignmentController extends Controller
{
    public function index(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;

        $status = $request->query('status');
        $status = in_array($status, [
            RiderAssignment::STATUS_PENDING,
            RiderAssignment::STATUS_ACCEPTED,
            RiderAssignment::STATUS_DECLINED,
        ], true) ? $status : null;

        $query = RiderAssignment::query()
            ->where('company_id', $companyId)
            ->with(['shipment.order', 'rider'])
            ->orderByDesc('id');

        if ($status !== null) {
            $query->where('status', $status);
        }

        $assignments = $query->get();

        $profiles = RiderProfile::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->with('employee')
            ->orderBy('id')
            ->get();

        // Shipments without an active (pending/accepted) rider assignment.
        $activeShipmentIds = RiderAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('status', [
                RiderAssignment::STATUS_PENDING,
                RiderAssignment::STATUS_ACCEPTED,
            ])
            ->pluck('shipment_id');

        $shipments = Shipment::query()
            ->where('company_id', $companyId)
            ->with('order')
            ->whereNotIn('id', $activeShipmentIds)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $courierById = Courier::query()
            ->where('company_id', $companyId)
            ->get()
            ->keyBy('id');

        return view('sales.delivery.rider-assignments', [
            'assignments' => $assignments,
            'profiles' => $profiles,
            'shipments' => $shipments,
            'courierById' => $courierById,
            'statusFilter' => $status,
            'statuses' => [
                RiderAssignment::STATUS_PENDING,
                RiderAssignment::STATUS_ACCEPTED,
                RiderAssignment::STATUS_DECLINED,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = (int) $request->user()->company_id;

        $data = $request->validate([
            'shipment_id' => ['required', 'integer', Rule::exists('shipments', 'id')
                ->where('company_id', $companyId)],
            'rider_profile_id' => ['required', 'integer', Rule::exists('rider_profiles', 'id')
                ->where('company_id', $companyId)],
        ]);

        $shipment = Shipment::query()
            ->where('company_id', $companyId)
            ->findOrFail((int) $data['shipment_id']);

        $profile = RiderProfile::query()
            ->where('company_id', $companyId)
            ->findOrFail((int) $data['rider_profile_id']);

        try {
            $assignment = app(AssignRider::class)->handle($shipment, $profile, $request);
        } catch (HttpExceptionInterface $e) {
            throw $e; // abort() responses (403/404/500) must not become form errors
        } catch (RuntimeException $e) {
            return back()->withErrors(['shipment_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.delivery.rider-assignments.index')
            ->with('status', sprintf(
                'Rider assigned to shipment — awaiting acceptance (assignment #%d).',
                $assignment->id,
            ));
    }

    public function accept(Request $request, RiderAssignment $assignment): RedirectResponse
    {
        return $this->respond($request, $assignment, true);
    }

    public function decline(Request $request, RiderAssignment $assignment): RedirectResponse
    {
        return $this->respond($request, $assignment, false);
    }

    protected function respond(Request $request, RiderAssignment $assignment, bool $accept): RedirectResponse
    {
        $companyId = (int) $request->user()->company_id;

        if ((int) $assignment->company_id !== $companyId) {
            abort(404);
        }

        try {
            $fresh = app(RespondToRiderAssignment::class)->handle($assignment, $accept, $request);
        } catch (HttpExceptionInterface $e) {
            throw $e; // abort(403) from the action reaches the client as-is
        } catch (RuntimeException $e) {
            return back()->withErrors(['assignment' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.delivery.rider-assignments.index')
            ->with('status', sprintf(
                'Assignment #%d %s.',
                $fresh->id,
                $accept ? 'accepted' : 'declined',
            ));
    }
}
