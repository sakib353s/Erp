<?php

namespace App\Http\Controllers;

use App\Domain\Masters\DeliveryZone;
use App\Domain\Masters\District;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Sales › Delivery zones (02-88): the zone list plus district coverage
 * and weight-slab charges on one screen. Zones are company-scoped; the
 * charge rows live in `zone_charges` and feed PricingService shipping.
 */
class DeliveryZoneController extends Controller
{
    public function index(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;

        $zones = DeliveryZone::query()
            ->where('company_id', $companyId)
            ->with(['districts', 'charges' => fn ($query) => $query->orderBy('weight_from')->orderBy('id')])
            ->orderBy('name')
            ->get();

        return view('sales.delivery.zones', [
            'zones' => $zones,
            'districts' => District::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = (int) $request->user()->company_id;

        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:32',
                Rule::unique('delivery_zones', 'code')->where('company_id', $companyId),
            ],
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:500'],
            'base_charge' => ['nullable', 'numeric', 'min:0'],
            'per_kg_charge' => ['nullable', 'numeric', 'min:0'],
            'district_ids' => ['nullable', 'array'],
            'district_ids.*' => ['integer', Rule::exists('districts', 'id')],
        ], [
            'code.unique' => 'A delivery zone with this code already exists.',
        ]);

        $isActive = $request->has('is_active');

        $zone = DB::transaction(function () use ($data, $companyId, $isActive) {
            $zone = DeliveryZone::create([
                'company_id' => $companyId,
                'code' => strtoupper($data['code']),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'base_charge' => (float) ($data['base_charge'] ?? 0),
                'per_kg_charge' => (float) ($data['per_kg_charge'] ?? 0),
                'is_active' => $isActive,
            ]);

            $zone->districts()->sync(array_map('intval', $data['district_ids'] ?? []));

            return $zone;
        });

        return back()->with('status', "Delivery zone {$zone->code} created.");
    }
}
