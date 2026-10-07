<?php

namespace Tests\Unit;

use App\Domain\Workflow\Services\WorkflowConditionEvaluator;
use App\Domain\Workflow\WorkflowCondition;
use App\Domain\Workflow\WorkflowDefinition;
use Tests\TestCase;

/**
 * Condition evaluator unit contract: AND inside a group, OR across
 * groups, every operator's real semantics (including the documented
 * lte-vs-value_max behaviour) and type normalisation via stringly().
 * Operates purely on model rows — no HTTP, no seeding.
 */
class WorkflowConditionEvaluatorTest extends TestCase
{
    protected function evaluator(): WorkflowConditionEvaluator
    {
        return new WorkflowConditionEvaluator;
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    protected function definitionWith(array $rows): WorkflowDefinition
    {
        $definition = new WorkflowDefinition;

        $conditions = collect($rows)
            ->map(fn (array $attributes, int $index) => new WorkflowCondition(
                array_merge(['condition_group' => 0, 'position' => $index], $attributes),
            ));

        return $definition->setRelation('conditions', $conditions);
    }

    public function test_definition_without_conditions_matches_every_context(): void
    {
        $definition = $this->definitionWith([]);

        $this->assertTrue($this->evaluator()->matches($definition, []));
        $this->assertTrue($this->evaluator()->matches($definition, ['amount' => 1.0]));
    }

    public function test_gte_operator(): void
    {
        $definition = $this->definitionWith([
            ['subject' => 'amount', 'operator' => 'gte', 'value_min' => 1000],
        ]);

        $evaluator = $this->evaluator();

        $this->assertTrue($evaluator->matches($definition, ['amount' => 1000.0]));
        $this->assertTrue($evaluator->matches($definition, ['amount' => 2500.0]));
        $this->assertFalse($evaluator->matches($definition, ['amount' => 999.99]));

        // A missing subject never satisfies a threshold (fail closed).
        $this->assertFalse($evaluator->matches($definition, []));
    }

    public function test_lte_operator_compares_against_value_min(): void
    {
        $definition = $this->definitionWith([
            ['subject' => 'amount', 'operator' => 'lte', 'value_min' => 1000],
        ]);

        $evaluator = $this->evaluator();

        $this->assertTrue($evaluator->matches($definition, ['amount' => 500.0]));
        $this->assertTrue($evaluator->matches($definition, ['amount' => 1000.0]));
        $this->assertFalse($evaluator->matches($definition, ['amount' => 1000.01]));
        $this->assertFalse($evaluator->matches($definition, []));
    }

    public function test_lte_ignores_value_max_exactly_as_implemented(): void
    {
        // Documented behaviour: the secondary clause only fires when
        // value_max is NULL — with a populated value_max the operator
        // still means "at most value_min".
        $withMax = $this->definitionWith([
            ['subject' => 'amount', 'operator' => 'lte', 'value_min' => 1000, 'value_max' => 2000],
        ]);

        $evaluator = $this->evaluator();

        $this->assertTrue($evaluator->matches($withMax, ['amount' => 500.0]));
        $this->assertFalse($evaluator->matches($withMax, ['amount' => 1500.0])); // inside [1000,2000] yet false
        $this->assertFalse($evaluator->matches($withMax, ['amount' => 2500.0]));
    }

    public function test_between_operator_is_inclusive(): void
    {
        $definition = $this->definitionWith([
            ['subject' => 'amount', 'operator' => 'between', 'value_min' => 1000, 'value_max' => 2000],
        ]);

        $evaluator = $this->evaluator();

        $this->assertFalse($evaluator->matches($definition, ['amount' => 999.0]));
        $this->assertTrue($evaluator->matches($definition, ['amount' => 1000.0]));
        $this->assertTrue($evaluator->matches($definition, ['amount' => 2000.0]));
        $this->assertFalse($evaluator->matches($definition, ['amount' => 2001.0]));
        $this->assertFalse($evaluator->matches($definition, []));
    }

    public function test_eq_and_neq_operators(): void
    {
        $currency = $this->definitionWith([
            ['subject' => 'currency', 'operator' => 'eq', 'value_string' => 'BDT'],
        ]);
        $numeric = $this->definitionWith([
            ['subject' => 'level', 'operator' => 'eq', 'value_min' => 5],
        ]);
        $notBdt = $this->definitionWith([
            ['subject' => 'currency', 'operator' => 'neq', 'value_string' => 'BDT'],
        ]);

        $evaluator = $this->evaluator();

        $this->assertTrue($evaluator->matches($currency, ['currency' => 'BDT']));
        $this->assertFalse($evaluator->matches($currency, ['currency' => 'USD']));
        $this->assertFalse($evaluator->matches($currency, []));

        $this->assertTrue($evaluator->matches($numeric, ['level' => 5]));
        $this->assertFalse($evaluator->matches($numeric, ['level' => 6]));

        $this->assertTrue($evaluator->matches($notBdt, ['currency' => 'USD']));
        $this->assertFalse($evaluator->matches($notBdt, ['currency' => 'BDT']));
        // neq with a missing subject compares '' !== 'BDT' → true.
        $this->assertTrue($evaluator->matches($notBdt, []));
    }

    public function test_in_and_not_in_use_string_normalisation(): void
    {
        $inList = $this->definitionWith([
            ['subject' => 'currency', 'operator' => 'in', 'value_list' => ['BDT', 'USD']],
        ]);
        $notInList = $this->definitionWith([
            ['subject' => 'currency', 'operator' => 'not_in', 'value_list' => ['BDT']],
        ]);

        $evaluator = $this->evaluator();

        $this->assertTrue($evaluator->matches($inList, ['currency' => 'BDT']));
        $this->assertTrue($evaluator->matches($inList, ['currency' => 'USD']));
        $this->assertFalse($evaluator->matches($inList, ['currency' => 'EUR']));

        $this->assertFalse($evaluator->matches($notInList, ['currency' => 'BDT']));
        $this->assertTrue($evaluator->matches($notInList, ['currency' => 'EUR']));
    }

    public function test_stringly_normalises_booleans_arrays_and_null(): void
    {
        // Booleans normalise to '1'/'0', arrays to compact JSON.
        $boolTrue = $this->definitionWith([
            ['subject' => 'flag', 'operator' => 'eq', 'value_string' => '1'],
        ]);
        $arrayJson = $this->definitionWith([
            ['subject' => 'tags', 'operator' => 'eq', 'value_string' => '["a","b"]'],
        ]);

        $evaluator = $this->evaluator();

        $this->assertTrue($evaluator->matches($boolTrue, ['flag' => true]));
        $this->assertFalse($evaluator->matches($boolTrue, ['flag' => false]));
        $this->assertTrue($evaluator->matches($arrayJson, ['tags' => ['a', 'b']]));
    }

    public function test_unknown_operator_fails_closed(): void
    {
        $definition = $this->definitionWith([
            ['subject' => 'amount', 'operator' => 'sounds_legit', 'value_min' => 1],
        ]);

        $this->assertFalse($this->evaluator()->matches($definition, ['amount' => 999.0]));
    }

    public function test_conditions_and_within_a_group_and_or_across_groups(): void
    {
        $definition = $this->definitionWith([
            // group 0: currency eq BDT AND amount gte 1000 (AND)
            ['subject' => 'currency', 'operator' => 'eq', 'value_string' => 'BDT', 'condition_group' => 0, 'position' => 0],
            ['subject' => 'amount', 'operator' => 'gte', 'value_min' => 1000, 'condition_group' => 0, 'position' => 1],
            // group 1: amount gte 100000 (OR)
            ['subject' => 'amount', 'operator' => 'gte', 'value_min' => 100000, 'condition_group' => 1, 'position' => 0],
        ]);

        $evaluator = $this->evaluator();

        // Group 0 fully satisfied.
        $this->assertTrue($evaluator->matches($definition, ['currency' => 'BDT', 'amount' => 5000.0]));
        // Group 0 broken by one condition; group 1 not reached (needs 100000).
        $this->assertFalse($evaluator->matches($definition, ['currency' => 'USD', 'amount' => 5000.0]));
        // Group 0 broken, group 1 satisfied → overall true.
        $this->assertTrue($evaluator->matches($definition, ['currency' => 'USD', 'amount' => 150000.0]));
    }
}
