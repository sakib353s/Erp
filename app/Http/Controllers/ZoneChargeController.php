<?php

namespace App\Http\Controllers;

use App\Domain\Masters\DeliveryZone;
use App\Domain\Masters\ZoneCharge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Weight-slab charge rows per delivery zone (02-88 "Zone-Wise
 * Charges"): created/removed from the zones screen, company-checked
 * against the requester, feeding PricingService::resolveShipping.
 */
class ZoneChargeController extends Controller
{
    public function store(Request $request, DeliveryZone $zone): RedirectResponse
    {
        $companyId = (int) $request->user()->company_id;
        abort_unless((int) $zone->company_id === $companyId, 404);

        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:32',
                Rule::unique('zone_charges', 'code')
                    ->where('company_id', $companyId)
                    ->where('delivery_zone_id', $zone->id),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'weight_from' => ['required', 'numeric', 'min:0'],
            'weight_to' => ['nullable', 'numeric', 'min:0', 'gte:weight_from'],
            'amount' => ['required', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'code.unique' => 'A charge with this code already exists for the zone.',
        ]);

        $charge = ZoneCharge::create([
            'company_id' => $companyId,
            'delivery_zone_id' => $zone->id,
            'code' => strtoupper($data['code']),
            'description' => $data['description'] ?? null,
            'weight_from' => (float) $data['weight_from'],
            'weight_to' => isset($data['weight_to']) && $data['weight_to'] !== null && $data['weight_to'] !== ''
                ? (float) $data['weight_to']
                : null,
            'amount' => (float) $data['amount'],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return back()->with('status', "Charge {$charge->code} added to zone {$zone->code}.");
    }

    public function destroy(Request $request, ZoneCharge $charge): RedirectResponse
    {
        abort_unless((int) $charge->company_id === (int) $request->user()->company_id, 404);

        $code = $charge->code;
        $zone = $charge->deliveryZone;
        $charge->delete();

        return back()->with('status', "Charge {$code} removed from zone {$zone?->code}.");
    }
}
