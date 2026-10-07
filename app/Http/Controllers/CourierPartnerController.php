<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\Couriers\ProviderRegistry;
use App\Domain\Masters\Courier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Courier partners screen (02-91): create/edit courier rows behind
 * sales.delivery.configure. Configuration truth lives on the row —
 * configuration_status + integration_enabled drive CourierPort, and
 * the webhook signing secret is write-only (encrypted at rest,
 * blank keeps the current value, never rendered back). The screen
 * never claims an integration works: it reports exactly what the
 * system knows.
 */
class CourierPartnerController extends Controller
{
    public function index(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;

        $editCourier = null;
        if ($request->query('edit')) {
            $editCourier = Courier::query()
                ->where('company_id', $companyId)
                ->find((int) $request->query('edit'));
        }

        $couriers = Courier::query()
            ->where('company_id', $companyId)
            ->withCount('shipments')
            ->orderBy('sort')
            ->orderBy('name')
            ->get();

        // 02-92: rows whose code matches a supported provider link to
        // their dedicated provider screen.
        $registry = app(ProviderRegistry::class);
        $providerSlugs = [];
        foreach ($couriers as $courier) {
            $slug = $registry->adapterFor($courier)->slug();
            if ($slug !== null) {
                $providerSlugs[$courier->id] = $slug;
            }
        }

        return view('settings.couriers', [
            'couriers' => $couriers,
            'editCourier' => $editCourier,
            'providerSlugs' => $providerSlugs,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = (int) $request->user()->company_id;

        $id = $request->input('id');

        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('couriers', 'code')
                    ->where('company_id', $companyId)
                    ->ignore($id !== null ? (int) $id : 0),
            ],
            'name' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:255'],
            'configuration_status' => ['required', Rule::in(['not_configured', 'configured'])],
            'integration_enabled' => ['sometimes', 'boolean'],
            'tracking_url_pattern' => ['nullable', 'string', 'max:255'],
            'webhook_secret' => ['nullable', 'string', 'min:8', 'max:128'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($id !== null) {
            $courier = Courier::query()
                ->where('company_id', $companyId)
                ->findOrFail((int) $id);
        } else {
            $courier = new Courier(['company_id' => $companyId]);
        }

        $attributes = [
            'code' => strtoupper($data['code']),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'configuration_status' => $data['configuration_status'],
            'integration_enabled' => $request->boolean('integration_enabled'),
            'tracking_url_pattern' => $data['tracking_url_pattern'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ];

        // Write-only secret: blank keeps whatever is stored, a value of
        // 8+ characters replaces it. It is never echoed anywhere.
        $secret = trim((string) ($data['webhook_secret'] ?? ''));
        if ($secret !== '') {
            $attributes['webhook_secret'] = $secret;
        }

        $courier->fill($attributes);
        $courier->save();

        return redirect()
            ->route('couriers.index')
            ->with('status', sprintf(
                'Courier %s %s.',
                $courier->code,
                $courier->wasRecentlyCreated ? 'created' : 'updated',
            ));
    }
}
