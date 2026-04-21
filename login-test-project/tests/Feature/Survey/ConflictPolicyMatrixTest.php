<?php

it('freezes the multiple-choice conflict-policy matrix contract', function () {
    $matrix = require base_path('tests/Support/ConflictPolicyMatrix.php');

    $multipleChoice = $matrix['multiple_choice'];
    $scenarios = $multipleChoice['scenarios'];
    $ids = collect($scenarios)->pluck('id')->all();

    expect($multipleChoice['name'])->toBe('Which LoL Champion Are You?')
        ->and($multipleChoice['entry'])->toBe('Q1')
        ->and($multipleChoice['base_graph']['canonical_paths'])->toHaveCount(11)
        ->and($ids)->toHaveCount(count(array_unique($ids)))
        ->and($ids)->toContain(
            'MC_ATOMIC_01',
            'MC_ATOMIC_02',
            'MC_RB_01',
            'MC_ATOMIC_03',
            'MC_RB_02',
            'MC_RB_03',
            'MC_RB_04',
            'MC_NUCLEAR_01',
            'MC_ATOMIC_04',
        );

    $policyClasses = collect($scenarios)->pluck('policy_class')->unique()->values()->all();

    expect($policyClasses)->toContain(
        'atomic_recovery',
        'rollback_to_fallback',
        'nuclear_restart',
        'text_label_only_edit',
    );

    foreach ($scenarios as $scenario) {
        expect($scenario)->toHaveKeys(['id', 'policy_class', 'title', 'checkpoint', 'mutation', 'expected'])
            ->and($scenario['expected'])->toHaveKeys([
                'recovery_strategy',
                'conflict_detected',
                'continue_from',
                'drop_answers',
                'must_not_show_unreachable',
            ]);
    }
});

it('freezes the linear conflict-policy matrix contracts for rating and open-ended', function () {
    $matrix = require base_path('tests/Support/ConflictPolicyMatrix.php');

    foreach (['rating', 'open_ended'] as $type) {
        $definition = $matrix[$type];
        $scenarios = $definition['scenarios'];
        $ids = collect($scenarios)->pluck('id')->all();

        expect($definition['base_graph']['canonical_paths'])->toHaveCount(1)
            ->and($ids)->toHaveCount(5)
            ->and($ids)->toHaveCount(count(array_unique($ids)))
            ->and($scenarios)->toHaveCount(5);

        $policyClasses = collect($scenarios)->pluck('policy_class')->unique()->values()->all();
        expect($policyClasses)->toContain(
            'atomic_recovery',
            'rollback_to_fallback',
            'nuclear_restart',
        );

        foreach ($scenarios as $scenario) {
            expect($scenario)->toHaveKeys(['id', 'policy_class', 'title', 'checkpoint', 'mutation', 'expected'])
                ->and($scenario['expected'])->toHaveKeys([
                    'recovery_strategy',
                    'conflict_detected',
                    'continue_from',
                    'drop_answers',
                    'must_not_show_unreachable',
                ]);
        }
    }
});
