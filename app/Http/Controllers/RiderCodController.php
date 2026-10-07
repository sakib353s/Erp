<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\Actions\RecordRiderCodCollection;
use App\Domain\Delivery\RiderAssignment;
use App\Domain\Delivery\RiderCodCollection;
use App\Domain\Delivery\RiderProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Rider COD collection board (02-93): cash recorded against accepted
 * assignments only, with an audit trail. No accounting posting —
 * reconciliation is 02-97.
 */
class RiderCodController extends Controller
{
    public function index(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;

        $collections = RiderCodCollection::query()
            ->where('company_id', $companyId)
            ->with(['assignment.shipment', 'rider'])
            ->orderByDesc('collected_at')
            ->orderByDesc('id')
            ->get();

        $total = (float) $collections->sum('amount');

        $collectable = RiderAssignment::query()
            ->where('company_id', $companyId)
            ->where('status', RiderAssignment::STATUS_ACCEPTED)
            ->whereNotNull('rider_employee_id')
            ->with(['shipment.order', 'rider'])
            ->orderByDesc('id')
            ->get();

        $riderOptions = RiderProfile::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->with('employee')
            ->orderBy('id')
            ->get();

        return view('sales.delivery.rider-cod', [
            'collections' => $collections,
            'total' => $total,
            'collectable' => $collectable,
            'riderOptions' => $riderOptions,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = (int) $request->user()->company_id;

        $data = $request->validate([
            'rider_assignment_id' => ['required', 'integer', Rule::exists('rider_assignments', 'id')
                ->where('company_id', $companyId)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'collected_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $assignment = RiderAssignment::query()
            ->where('company_id', $companyId)
            ->findOrFail((int) $data['rider_assignment_id']);

        try {
            app(RecordRiderCodCollection::class)->handle($assignment, [
                'amount' => $data['amount'],
                'collected_at' => $data['collected_at'] ?? null,
                'notes' => $data['notes'] ?? null,
            ], $request);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return back()->withErrors(['rider_assignment_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.delivery.rider-cod.index')
            ->with('status', 'COD collection recorded.');
    }
}
