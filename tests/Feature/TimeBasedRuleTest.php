<?php

namespace Tests\Feature;

use App\Domain\Masters\PricingRule;
use App\Domain\Sales\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPricingRules;
use Tests\TestCase;

/**
 * 02-111 time-based pricing: inclusive date windows and time-of-day
 * windows resolved against the caller's timestamp; a bare date covers
 * the whole day (orders quote by date, POS by date).
 */
class TimeBasedRuleTest extends TestCase
{
    use BuildsPricingRules;
    use RefreshDatabase;

    protected PricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPricing();
        $this->pricing = app(PricingService::class);
    }

    public function test_date_window_applies_only_between_valid_from_and_valid_to(): void
    {
        $this->makeRule([
            'rule_type' => PricingRule::TYPE_TIME_BASED,
            'name' => 'September special',
            'valid_from' => '2026-09-01',
            'valid_to' => '2026-09-30',
            'price' => 80,
            'percent_off' => null,
        ]);

        $this->assertEqualsWithDelta(
            80.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, '2026-09-15 12:00:00'),
            0.0001,
        );

        // Boundaries are inclusive.
        $this->assertEqualsWithDelta(
            80.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, '2026-09-01 00:00:00'),
            0.0001,
        );

        // Outside the window the base list price stands.
        $this->assertEqualsWithDelta(
            100.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, '2026-08-31 23:59:00'),
            0.0001,
        );
        $this->assertEqualsWithDelta(
            100.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, '2026-10-01 00:00:00'),
            0.0001,
        );
    }

    public function test_time_of_day_window_applies_only_inside_business_hours(): void
    {
        $this->makeRule([
            'rule_type' => PricingRule::TYPE_TIME_BASED,
            'name' => 'Daytime promo',
            'time_from' => '09:00',
            'time_to' => '17:00',
            'price' => null,
            'percent_off' => 20,
        ]);

        $this->assertEqualsWithDelta(
            80.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, '2026-09-15 12:00:00'),
            0.0001,
        );

        $this->assertEqualsWithDelta(
            100.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, '2026-09-15 20:00:00'),
            0.0001,
        );

        $this->assertEqualsWithDelta(
            100.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, '2026-09-15 08:59:00'),
            0.0001,
        );
    }

    public function test_time_window_boundaries_are_inclusive(): void
    {
        $this->makeRule([
            'rule_type' => PricingRule::TYPE_TIME_BASED,
            'name' => 'Boundary promo',
            'time_from' => '09:00',
            'time_to' => '17:00',
            'price' => 70,
            'percent_off' => null,
        ]);

        $this->assertEqualsWithDelta(
            70.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, '2026-09-15 09:00:00'),
            0.0001,
        );
        $this->assertEqualsWithDelta(
            70.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, '2026-09-15 17:00:00'),
            0.0001,
        );
    }

    public function test_a_bare_date_covers_the_whole_day(): void
    {
        $this->makeRule([
            'rule_type' => PricingRule::TYPE_TIME_BASED,
            'name' => 'Daytime promo',
            'valid_from' => '2026-09-15',
            'valid_to' => '2026-09-15',
            'time_from' => '09:00',
            'time_to' => '17:00',
            'price' => 75,
            'percent_off' => null,
        ]);

        // Orders and POS pass date-only timestamps — the day matches as a whole.
        $this->assertEqualsWithDelta(
            75.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, '2026-09-15'),
            0.0001,
        );

        // …but a date outside the window still misses.
        $this->assertEqualsWithDelta(
            100.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, '2026-09-16'),
            0.0001,
        );
    }

    public function test_time_based_rules_render_their_window_on_the_rules_screen(): void
    {
        $this->actingAs($this->admin)
            ->post(route('pricing.rules.store'), $this->rulePayload([
                'rule_type' => PricingRule::TYPE_TIME_BASED,
                'name' => 'Happy hours',
                'price' => 95,
                'percent_off' => null,
                'time_from' => '14:00',
                'time_to' => '16:00',
                'valid_from' => '2026-09-01',
                'valid_to' => '2026-09-30',
            ]))
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->get(route('pricing.rules.index', ['type' => 'time_based']))
            ->assertOk()
            ->assertSee('Happy hours')
            ->assertSee('14:00')
            ->assertSee('2026-09-01');
    }
}
