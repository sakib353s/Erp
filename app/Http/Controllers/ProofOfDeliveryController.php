<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\Actions\RecordProofOfDelivery;
use App\Domain\Delivery\ProofOfDelivery;
use App\Domain\Delivery\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Proof of delivery screen (02-95): queue of dispatched shipments
 * awaiting POD and the record form (signature/photo/receiver name).
 * POST /app/sales/shipments/{shipment}/pod sits behind
 * sales.delivery.pod; at least one proof is required and files go
 * through the safe MIME-sniffing upload pipeline.
 */
class ProofOfDeliveryController extends Controller
{
    public function index(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;

        $ready = Shipment::query()
            ->where('company_id', $companyId)
            ->whereIn('status', [
                Shipment::STATUS_DISPATCHED,
                Shipment::STATUS_OUT_FOR_DELIVERY,
            ])
            ->with(['order', 'courier'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $recent = ProofOfDelivery::query()
            ->where('company_id', $companyId)
            ->with(['shipment.order', 'signatureDocument', 'photoDocument'])
            ->orderByDesc('delivered_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('sales.delivery.pod', [
            'ready' => $ready,
            'recent' => $recent,
        ]);
    }

    public function store(Request $request, Shipment $shipment): RedirectResponse
    {
        $companyId = (int) $request->user()->company_id;

        if ((int) $shipment->company_id !== $companyId) {
            abort(404);
        }

        $data = $request->validate([
            'receiver_name' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:500'],
            'delivered_at' => ['nullable', 'date', 'before_or_equal:now'],
            'signature' => ['nullable', 'file'],
            'photo' => ['nullable', 'file'],
        ]);

        $hasReceiver = trim((string) ($data['receiver_name'] ?? '')) !== '';
        if (! $hasReceiver && ! $request->hasFile('signature') && ! $request->hasFile('photo')) {
            return back()->withErrors([
                'proof' => 'Record at least one proof: a signature, a photo, or the receiver name.',
            ]);
        }

        try {
            $pod = app(RecordProofOfDelivery::class)->handle($shipment, [
                'receiver_name' => $data['receiver_name'] ?? null,
                'notes' => $data['notes'] ?? null,
                'delivered_at' => $data['delivered_at'] ?? null,
                'signature' => $request->file('signature'),
                'photo' => $request->file('photo'),
            ], $request);
        } catch (HttpExceptionInterface $e) {
            throw $e; // abort() responses (403/404/500) must not become form errors
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['proof' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            return back()->withErrors(['proof' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.delivery.pod.index')
            ->with('status', sprintf(
                'Proof of delivery recorded for shipment #%d — delivered.',
                $pod->shipment_id,
            ));
    }
}
