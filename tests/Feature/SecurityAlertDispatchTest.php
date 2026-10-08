<?php

namespace Tests\Feature;

use App\Domain\Foundation\User;
use App\Domain\Notification\Notification;
use App\Domain\Security\SecurityAlert;
use App\Domain\Security\Services\SecureAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §16-36 — the secure alert dispatch: a severity list, a per-severity rate limit,
 * and dual control over the critical alerts.
 *
 * A security alert is the one message where "just send it" is dangerous: a burst can
 * become a storm, and a critical alert that fires on every blip trains people to
 * ignore the one that mattered. So raising an alert is gated — only known
 * severities, a flood is bounded, and a critical alert is held for a second person
 * to approve before it reaches the watchers.
 */
class SecurityAlertDispatchTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());
    }

    protected function service(): SecureAlertService
    {
        return app(SecureAlertService::class);
    }

    public function test_only_known_severities_may_be_raised(): void
    {
        $this->expectException(HttpException::class);

        $this->service()->raise('test.weird', 'p1', 'Odd severity');
    }

    public function test_a_low_severity_alert_dispatches_immediately(): void
    {
        $before = Notification::query()->count();

        $result = $this->service()->raise('test.low', 'low', 'Routine event');

        $this->assertFalse($result['rate_limited']);
        $this->assertTrue($result['dispatched']);
        $this->assertSame('open', $result['alert']->status);
        // A non-critical alert goes out without waiting for anyone.
        $this->assertSame($before + 1, Notification::query()->count());
    }

    public function test_a_critical_alert_is_held_for_dual_control(): void
    {
        $before = Notification::query()->count();

        $result = $this->service()->raise('login.brute_force', 'critical', 'Possible brute-force attack');

        // Held, not broadcast.
        $this->assertSame('pending_dual_control', $result['alert']->status);
        $this->assertFalse($result['dispatched']);
        $this->assertSame($before, Notification::query()->count(), 'A critical alert must not notify until approved.');

        // A second authorized person approves it — now it is released.
        $second = $this->makeUser(['is_superadmin' => true]);
        $alert = $this->service()->approve($result['alert'], $second->id);

        $this->assertSame('dispatched', $alert->status);
        $this->assertSame($before + 1, Notification::query()->count(), 'Dual control releases the alert to the watchers.');
    }

    public function test_unapproved_critical_alert_cannot_be_approved_again_without_state(): void
    {
        $result = $this->service()->raise('login.brute_force', 'critical', 'Possible brute-force attack');

        // Once approved, it can no longer be re-approved as pending.
        $second = $this->makeUser(['is_superadmin' => true]);
        $this->service()->approve($result['alert'], $second->id);

        $this->expectException(HttpException::class);
        $this->service()->approve($result['alert']->fresh(), $second->id);
    }

    public function test_a_severity_burst_is_rate_limited_not_stormed(): void
    {
        // Critical alerts are capped at 1 per window, so a burst cannot create a storm.
        $first = $this->service()->raise('login.brute_force', 'critical', 'Burst 1');
        $second = $this->service()->raise('login.brute_force', 'critical', 'Burst 2');
        $third = $this->service()->raise('login.brute_force', 'critical', 'Burst 3');

        $this->assertFalse($first['rate_limited']);
        $this->assertTrue($second['rate_limited']);
        $this->assertTrue($third['rate_limited']);

        // Only one critical alert was actually written; the rest returned the same row.
        $this->assertSame($first['alert']->id, $second['alert']->id);
        $this->assertSame(1, SecurityAlert::query()->where('severity', 'critical')->count());
    }
}
