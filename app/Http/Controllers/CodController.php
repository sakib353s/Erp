<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\Actions\ReconcileCod;
use App\Domain\Delivery\Services\CodQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * COD collection tracking + reconciliation screen (02-97): real
 * collections with their outstanding/pending/reconciled state and the
 * cash-vs-remittance match behind sales.delivery.cod.reconcile.
 * No GL in the controller — ReconcileCod owns the cod_remittance
 * posting and the invoice settlements.
 */
class CodController extends Controller
{
    public function index(Request $request): View
    {
        $report = app(CodQuery::class)->report((int) $request->user()->company_id);

        return view('sales.delivery.cod', [
            'rows' => $report['rows'],
            'open' => $report['rows']->filter(
                fn (array $r) => $r['state'] === 'outstanding',
            ),
            'riders' => $report['riders'],
            'reconciliations' => $report['reconciliations'],
            'totals' => $report['totals'],
        ]);
    }

    public function reconcile(Request $request): RedirectResponse
    {
        $companyId = (int) $request->user()->company_id;

        $data = $request->validate([
            'collection_ids' => ['required', 'array', 'min:1'],
            'collection_ids.*' => ['integer', Rule::exists('rider_cod_collections', 'id')
                ->where('company_id', $companyId)],
            'remitted_amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'remitted_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $row = app(ReconcileCod::class)->handle([
                'collection_ids' => $data['collection_ids'],
                'remitted_amount' => $data['remitted_amount'],
                'remitted_at' => $data['remitted_at'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ], $request);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return back()->withErrors(['cod' => $e->getMessage()]);
        }

        $status = $row->isReconciled()
            ? 'COD reconciled — cash matched to the remittance.'
            : 'COD reconciliation submitted for approval.';

        return redirect()
            ->route('sales.delivery.cod.index')
            ->with('status', $status);
    }
}
