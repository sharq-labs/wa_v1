<?php

use App\Services\Automation\ConditionEvaluatorForSimulation;

// The simulation evaluator mirrors ConditionEvaluator's compare() semantics
// against a plain state array, which makes operator behaviour easy to verify.
function evaluateAgainst(array $state, array $config): bool
{
    return (new ConditionEvaluatorForSimulation)->evaluate($state, $config);
}

$baseState = [
    'contact' => ['first_name' => 'Ahmed', 'country' => 'EG'],
    'custom_fields' => ['budget' => '50000', 'company' => ''],
    'variables' => ['plan' => 'pro'],
    'tags' => ['Lead', 'VIP'],
    'last_message' => 'I want the price please',
    'conversation' => ['status' => 'open'],
];

it('evaluates equals / not equals', function () use ($baseState) {
    expect(evaluateAgainst($baseState, [
        'match' => 'all',
        'conditions' => [['source' => 'contact', 'key' => 'country', 'operator' => 'equals', 'value' => 'EG']],
    ]))->toBeTrue();

    expect(evaluateAgainst($baseState, [
        'match' => 'all',
        'conditions' => [['source' => 'contact', 'key' => 'country', 'operator' => 'not_equals', 'value' => 'EG']],
    ]))->toBeFalse();
});

it('evaluates numeric comparisons', function () use ($baseState) {
    expect(evaluateAgainst($baseState, [
        'conditions' => [['source' => 'custom', 'key' => 'budget', 'operator' => 'greater_than', 'value' => '10000']],
    ]))->toBeTrue();

    expect(evaluateAgainst($baseState, [
        'conditions' => [['source' => 'custom', 'key' => 'budget', 'operator' => 'less_than', 'value' => '10000']],
    ]))->toBeFalse();
});

it('evaluates message contains', function () use ($baseState) {
    expect(evaluateAgainst($baseState, [
        'conditions' => [['source' => 'message', 'operator' => 'contains', 'value' => 'price']],
    ]))->toBeTrue();
});

it('evaluates tag membership', function () use ($baseState) {
    expect(evaluateAgainst($baseState, [
        'conditions' => [['source' => 'tag', 'operator' => 'contains', 'value' => 'vip']],
    ]))->toBeTrue();

    expect(evaluateAgainst($baseState, [
        'conditions' => [['source' => 'tag', 'operator' => 'not_contains', 'value' => 'Customer']],
    ]))->toBeTrue();
});

it('evaluates empty / not empty', function () use ($baseState) {
    expect(evaluateAgainst($baseState, [
        'conditions' => [['source' => 'custom', 'key' => 'company', 'operator' => 'empty']],
    ]))->toBeTrue();
});

it('combines AND and OR groups', function () use ($baseState) {
    $and = [
        'match' => 'all',
        'conditions' => [
            ['source' => 'contact', 'key' => 'country', 'operator' => 'equals', 'value' => 'EG'],
            ['source' => 'custom', 'key' => 'budget', 'operator' => 'greater_than', 'value' => '99999'],
        ],
    ];
    expect(evaluateAgainst($baseState, $and))->toBeFalse();

    $or = $and;
    $or['match'] = 'any';
    expect(evaluateAgainst($baseState, $or))->toBeTrue();
});
