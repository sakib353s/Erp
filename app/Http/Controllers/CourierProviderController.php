<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\Actions\TrackShipmentEvent;
use App\Domain\Delivery\Couriers\CourierAdapter;
use App\Domain\Delivery\Couriers\ProviderRegistry;
use App\Domain\Masters\Courier;
use App\Domain\Masters\District;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Per-provider courier configuration screen (02-92):
 * GET|PUT /app/settings/couriers/{provider}. The screen shows the
 * adapter's identity, its four capabilities with their honest current
 * state, the adapter-declared webhook vocabulary, and a rate probe that
 * only ever quotes from the tenant's own delivery zones (source
 * labeled). PUT upserts the provider's courier row by canonical code —
 * the webhook secret stays write-only, and no endpoint here claims a
 * connection test it cannot perform.
 */
class CourierProviderController extends Controller
{
    public function __construct(
        protected ProviderRegistry $providers,
    ) {}

    public function show(Request $request, string $provider): View
    {
        $adapter = $this->providers->resolve($provider);
        abort_unless($adapter !== null, 404);

        $courier = $this->courierRow((int) $request->user()->company_id, $adapter);

        return view('settings.courier-provider', [
            'adapter' => $adapter,
            'slug' => $provider,
            'courier' => $courier->exists ? $courier : null,
            'formCourier' => $courier,
            'districts' => District::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'codes' => TrackShipmentEvent::CODES,
            'probe' => $this->probe($request, $adapter, $courier),
            'probeDistrict' => (int) $request->query('district_id', 0),
            'probeWeight' => (string) $request->query('weight_kg', ''),
        ]);
    }

    public function update(Request $request, string $provider): RedirectResponse
    {
        $adapter = $this->providers->resolve($provider);
        abort_unless($adapter !== null, 404);

        $companyId = (int) $request->user()->company_id;

        $data = $request->validate([
            'configuration_status' => ['required', Rule::in(['not_configured', 'configured'])],
            'integration_enabled' => ['sometimes', 'boolean'],
            'tracking_url_pattern' => ['nullable', 'string', 'max:255'],
            'webhook_secret' => ['nullable', 'string', 'min:8', 'max:128'],
            'name' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $courier = $this->courierRow($companyId, $adapter);

        $courier->configuration_status = $data['configuration_status'];
        $courier->integration_enabled = $request->boolean('integration_enabled');
        $courier->is_active = $request->boolean('is_active');

        if (array_key_exists('tracking_url_pattern', $data)) {
            $courier->tracking_url_pattern = $data['tracking_url_pattern'];
        }
        if (array_key_exists('description', $data)) {
            $courier->description = $data['description'];
        }
        if (isset($data['name'])) {
            $courier->name = $data['name'];
        } elseif (! $courier->exists) {
            $courier->name = $adapter->label();
        }

        // Write-only secret: blank keeps the stored value, 8+ chars
        // replaces it. It is never echoed anywhere.
        $secret = trim((string) ($data['webhook_secret'] ?? ''));
        if ($secret !== '') {
            $courier->webhook_secret = $secret;
        }

        $courier->save();

        return back()->with('status', sprintf(
            '%s configuration %s.',
            $adapter->label(),
            $courier->wasRecentlyCreated ? 'created' : 'updated',
        ));
    }

    /** Rate probe: local zone quote or an honest reason, never a guess. */
    protected function probe(Request $request, CourierAdapter $adapter, Courier $courier): ?array
    {
        $districtId = $request->query('district_id');
        if ($districtId === null || $districtId === '') {
            return null;
        }

        $district = District::query()->find((int) $districtId);
        if ($district === null) {
            return [
                'available' => false,
                'source' => null,
                'reason' => 'Unknown district.',
            ];
        }

        $weight = $request->query('weight_kg');
        $weight = is_numeric($weight) ? max(0.0, (float) $weight) : 0.0;

        return $adapter->quoteRate($courier, [
            'district_id' => $district->id,
            'weight_kg' => $weight,
        ]);
    }

    protected function courierRow(int $companyId, CourierAdapter $adapter): Courier
    {
        return Courier::query()->firstOrNew(
            ['company_id' => $companyId, 'code' => $adapter->code()],
            [
                'name' => $adapter->label(),
                'configuration_status' => 'not_configured',
                'is_active' => true,
            ],
        );
    }
}
