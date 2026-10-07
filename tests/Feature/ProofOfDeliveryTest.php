<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Delivery\ProofOfDelivery;
use App\Domain\Delivery\Shipment;
use App\Domain\Delivery\TrackingEvent;
use App\Domain\Documents\Document;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Masters\Courier;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-95a Proof of delivery at GET /app/sales/delivery/pod +
 * POST /app/sales/shipments/{id}/pod behind sales.delivery.pod:
 * at least one proof (signature file, photo file, or receiver name),
 * files through the safe upload pipeline (server-sniffed content —
 * a renamed file is refused), only dispatched/out-for-delivery
 * shipments qualify, exactly one POD + one delivered tracking event
 * per shipment, company scoping, and the sales.pod_recorded audit.
 */
class ProofOfDeliveryTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);

        Storage::fake('local');

        $this->courier = Courier::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'PODX',
            'name' => 'POD Courier',
            'configuration_status' => 'pending',
            'integration_enabled' => false,
            'is_active' => true,
        ]);
    }

    protected function makeShipment(string $orderNo, string $status = Shipment::STATUS_DISPATCHED): Shipment
    {
        $customerId = DB::table('customers')->insertGetId([
            'company_id' => $this->admin->company_id,
            'code' => 'PODC-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'POD Customer '.$orderNo,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = DB::table('sales_orders')->insertGetId([
            'company_id' => $this->admin->company_id,
            'customer_id' => $customerId,
            'order_no' => $orderNo,
            'status' => 'confirmed',
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shipmentId = DB::table('shipments')->insertGetId([
            'company_id' => $this->admin->company_id,
            'sales_order_id' => $orderId,
            'courier_id' => $this->courier->id,
            'status' => $status,
            'dispatched_at' => $status === Shipment::STATUS_DISPATCHED ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Shipment::query()->findOrFail($shipmentId);
    }

    protected function postPod(Shipment $shipment, array $data = []): TestResponse
    {
        return $this->actingAs($this->admin)
            ->post(route('sales.delivery.pod.store', $shipment), $data);
    }

    public function test_pod_with_signature_and_photo_stores_documents_and_marks_delivered(): void
    {
        $shipment = $this->makeShipment('POD-1');

        $this->postPod($shipment, [
            'receiver_name' => 'Karim Uddin',
            'notes' => 'Left at the gate',
            'delivered_at' => now()->subHour()->toDateTimeString(),
            'signature' => UploadedFile::fake()->image('signature.png', 40, 20),
            'photo' => UploadedFile::fake()->image('proof.jpg', 60, 40),
        ])
            ->assertRedirect(route('sales.delivery.pod.index'))
            ->assertSessionHas('status');

        $pod = ProofOfDelivery::query()->where('shipment_id', $shipment->id)->firstOrFail();
        $this->assertSame('Karim Uddin', $pod->receiver_name);
        $this->assertSame('Left at the gate', $pod->notes);
        $this->assertSame(
            now()->subHour()->format('Y-m-d H:i'),
            $pod->delivered_at->format('Y-m-d H:i'),
        );
        $this->assertSame((int) $this->admin->id, (int) $pod->recorded_by);
        $this->assertNotNull($pod->signature_document_id);
        $this->assertNotNull($pod->photo_document_id);

        $signature = Document::query()->findOrFail($pod->signature_document_id);
        $this->assertSame(Shipment::class, $signature->owner_type);
        $this->assertSame((int) $shipment->id, (int) $signature->owner_id);
        $this->assertSame('signature', $signature->purpose);
        $this->assertSame('image/png', $signature->mime_type);
        $this->assertSame('not_scanned', $signature->scan_status); // truthful: no scanner yet
        $this->assertSame(
            hash('sha256', (string) Storage::disk('local')->get($signature->path)),
            $signature->checksum,
        );

        $photo = Document::query()->findOrFail($pod->photo_document_id);
        $this->assertSame('attachment', $photo->purpose);
        $this->assertSame('image/jpeg', $photo->mime_type);

        $this->assertSame(Shipment::STATUS_DELIVERED, $shipment->fresh()->status);

        $event = TrackingEvent::query()
            ->where('shipment_id', $shipment->id)
            ->where('event_code', 'delivered')
            ->firstOrFail();
        $this->assertSame('manual', $event->source);
        $this->assertSame('pod:'.$shipment->id, $event->idempotency_key);
        $this->assertStringContainsString('received by Karim Uddin', $event->description);

        $audit = AuditEvent::query()
            ->where('action', 'sales.pod_recorded')
            ->where('entity_id', $pod->id)
            ->firstOrFail();
        $this->assertSame((int) $shipment->id, (int) $audit->after['shipment_id']);
        $this->assertSame('Karim Uddin', $audit->after['receiver_name']);
        $this->assertSame('created', $audit->after['tracking_outcome']);

        $this->assertSame(2, AuditEvent::query()->where('action', 'document.upload')->count());

        $this->actingAs($this->admin)
            ->get(route('sales.delivery.pod.index'))
            ->assertOk()
            ->assertSee('Karim Uddin');
    }

    public function test_pod_with_receiver_name_only_needs_no_files(): void
    {
        $shipment = $this->makeShipment('POD-2');

        $this->postPod($shipment, ['receiver_name' => 'Rahima Begum'])
            ->assertRedirect(route('sales.delivery.pod.index'));

        $pod = ProofOfDelivery::query()->where('shipment_id', $shipment->id)->firstOrFail();
        $this->assertSame('Rahima Begum', $pod->receiver_name);
        $this->assertNull($pod->signature_document_id);
        $this->assertNull($pod->photo_document_id);
        $this->assertSame(0, Document::query()->count());
        $this->assertSame(Shipment::STATUS_DELIVERED, $shipment->fresh()->status);

        $this->actingAs($this->admin)
            ->get(route('sales.delivery.pod.index'))
            ->assertOk()
            ->assertSee('Rahima Begum')
            ->assertSee('none');
    }

    public function test_pod_requires_at_least_one_proof(): void
    {
        $shipment = $this->makeShipment('POD-3');

        $this->postPod($shipment, [])
            ->assertSessionHasErrors('proof');

        $this->assertSame(0, ProofOfDelivery::query()->count());
        $this->assertSame(Shipment::STATUS_DISPATCHED, $shipment->fresh()->status);
    }

    public function test_future_delivered_at_is_rejected(): void
    {
        $shipment = $this->makeShipment('POD-4');

        $this->postPod($shipment, [
            'receiver_name' => 'Tomorrow Man',
            'delivered_at' => now()->addDay()->toDateTimeString(),
        ])->assertSessionHasErrors('delivered_at');

        $this->assertSame(0, ProofOfDelivery::query()->count());
        $this->assertSame(Shipment::STATUS_DISPATCHED, $shipment->fresh()->status);
    }

    public function test_files_are_checked_by_content_not_by_name(): void
    {
        $shipment = $this->makeShipment('POD-5');

        // Renamed content: extension says png, bytes say text.
        $this->postPod($shipment, [
            'receiver_name' => 'Sneaky',
            'signature' => UploadedFile::fake()->createWithContent('signature.png', 'definitely not a png'),
        ])->assertSessionHasErrors('proof');
        $mismatch = (string) session('errors')->get('proof')[0];
        $this->assertStringContainsString('does not match its extension', $mismatch);

        // Disallowed extension outright.
        $this->postPod($shipment, [
            'receiver_name' => 'Sneaky',
            'photo' => UploadedFile::fake()->create('payload.php', 20),
        ])->assertSessionHasErrors('proof');
        $rejected = (string) session('errors')->get('proof')[0];
        $this->assertStringContainsString('is not allowed', $rejected);

        $this->assertSame(0, Document::query()->count());
        $this->assertSame(0, ProofOfDelivery::query()->count());
        $this->assertSame(Shipment::STATUS_DISPATCHED, $shipment->fresh()->status);
    }

    public function test_only_en_route_shipments_can_record_pod(): void
    {
        $pending = $this->makeShipment('POD-6', Shipment::STATUS_PENDING_DISPATCH);
        $this->postPod($pending, ['receiver_name' => 'Too Early'])
            ->assertSessionHasErrors('proof');
        $this->assertStringContainsString(
            'from status [pending_dispatch]',
            session('errors')->get('proof')[0],
        );
        $this->assertSame(Shipment::STATUS_PENDING_DISPATCH, $pending->fresh()->status);

        $done = $this->makeShipment('POD-7', Shipment::STATUS_DELIVERED);
        $this->postPod($done, ['receiver_name' => 'Again'])
            ->assertSessionHasErrors('proof');
        $this->assertStringContainsString(
            'from status [delivered]',
            session('errors')->get('proof')[0],
        );

        $this->assertSame(0, ProofOfDelivery::query()->count());
    }

    public function test_pod_routes_require_sales_delivery_pod(): void
    {
        $shipment = $this->makeShipment('POD-8');

        $denied = $this->makeUser(['name' => 'POD Viewer']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.delivery.pod.index'))
            ->assertForbidden();
        $this->actingAs($denied)
            ->post(route('sales.delivery.pod.store', $shipment), ['receiver_name' => 'Nope'])
            ->assertForbidden();
        $this->assertSame(0, ProofOfDelivery::query()->count());

        $allowed = $this->makeUser(['name' => 'POD Recorder']);
        $allowed->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.pod'])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.delivery.pod.index'))
            ->assertOk();
        $this->actingAs($allowed)
            ->post(route('sales.delivery.pod.store', $shipment), ['receiver_name' => 'Granted'])
            ->assertRedirect(route('sales.delivery.pod.index'));
        $this->assertSame(1, ProofOfDelivery::query()->count());
    }

    public function test_pod_is_company_scoped(): void
    {
        $shadowId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow POD Ltd',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowCourierId = DB::table('couriers')->insertGetId([
            'company_id' => $shadowId,
            'code' => 'SHDW',
            'name' => 'Shadow Courier',
            'configuration_status' => 'pending',
            'integration_enabled' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowCustomerId = DB::table('customers')->insertGetId([
            'company_id' => $shadowId,
            'code' => 'SHDW-C1',
            'name' => 'Shadow Customer',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowOrderId = DB::table('sales_orders')->insertGetId([
            'company_id' => $shadowId,
            'customer_id' => $shadowCustomerId,
            'order_no' => 'POD-SHADOW',
            'status' => 'confirmed',
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowShipmentId = DB::table('shipments')->insertGetId([
            'company_id' => $shadowId,
            'sales_order_id' => $shadowOrderId,
            'courier_id' => $shadowCourierId,
            'status' => Shipment::STATUS_DISPATCHED,
            'dispatched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postPod(Shipment::query()->findOrFail($shadowShipmentId), [
            'receiver_name' => 'Foreign Receiver',
        ])->assertNotFound();

        $this->assertSame(0, ProofOfDelivery::query()->count());
        $this->assertSame(
            Shipment::STATUS_DISPATCHED,
            Shipment::query()->findOrFail($shadowShipmentId)->status,
        );
    }

    public function test_recording_pod_twice_is_refused_with_one_row_and_one_event(): void
    {
        $shipment = $this->makeShipment('POD-9');

        $this->postPod($shipment, ['receiver_name' => 'First'])->assertSessionHasNoErrors();

        $this->postPod($shipment, ['receiver_name' => 'Second'])
            ->assertSessionHasErrors('proof');
        $this->assertStringContainsString(
            'from status [delivered]',
            session('errors')->get('proof')[0],
        );

        $this->assertSame(1, ProofOfDelivery::query()->count());
        $this->assertSame(1, TrackingEvent::query()
            ->where('shipment_id', $shipment->id)
            ->where('event_code', 'delivered')
            ->count());
        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'sales.pod_recorded')
            ->count());
        $this->assertSame('First', ProofOfDelivery::query()->firstOrFail()->receiver_name);
    }

    public function test_pod_screen_is_honest_about_empty_and_ready_states(): void
    {
        $this->actingAs($this->admin)
            ->get(route('sales.delivery.pod.index'))
            ->assertOk()
            ->assertSee('No shipments awaiting proof of delivery')
            ->assertSee('No proof recorded yet.');

        $shipment = $this->makeShipment('POD-10');

        $this->actingAs($this->admin)
            ->get(route('sales.delivery.pod.index'))
            ->assertOk()
            ->assertSee('Shipment #'.$shipment->id)
            ->assertSee('POD-10');

        $this->postPod($shipment, ['receiver_name' => 'Done Person']);

        $this->actingAs($this->admin)
            ->get(route('sales.delivery.pod.index'))
            ->assertOk()
            ->assertSee('No shipments awaiting proof of delivery')
            ->assertSee('Done Person');
    }
}
