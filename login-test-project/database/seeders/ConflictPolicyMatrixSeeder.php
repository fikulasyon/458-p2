<?php

namespace Database\Seeders;

use App\Models\QuestionEdge;
use App\Models\QuestionOption;
use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\SurveyVersion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class ConflictPolicyMatrixSeeder extends Seeder
{
    public function run(): void
    {
        $matrix = require base_path('tests/Support/ConflictPolicyMatrix.php');
        if (! is_array($matrix) || empty($matrix)) {
            throw new RuntimeException('Conflict policy matrix is missing or empty.');
        }

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Survey Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_admin' => true,
            ],
        );

        if (! $admin->is_admin) {
            $admin->forceFill(['is_admin' => true])->save();
        }

        DB::transaction(function () use ($admin, $matrix): void {
            foreach ($matrix as $surveyType => $definition) {
                if (! is_array($definition)) {
                    continue;
                }

                $this->seedSurveyType($admin, (string) $surveyType, $definition);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function seedSurveyType(User $admin, string $surveyType, array $definition): void
    {
        if (! in_array($surveyType, ['multiple_choice', 'rating', 'open_ended'], true)) {
            return;
        }

        $surveyTitle = (string) ($definition['seed_survey_title'] ?? (($definition['name'] ?? strtoupper($surveyType)).' [Conflict Matrix]'));
        $surveyName = (string) ($definition['name'] ?? $surveyType);

        $existing = Survey::query()->where('title', $surveyTitle)->first();
        if ($existing) {
            $existing->delete();
        }

        $survey = Survey::query()->create([
            'title' => $surveyTitle,
            'description' => "Generated from tests/Support/ConflictPolicyMatrix.php for {$surveyName} scenario drafts.",
            'survey_type' => $surveyType,
            'created_by' => $admin->id,
        ]);

        $baseGraph = $this->normalizeGraph($definition['base_graph'] ?? []);
        $defaultCheckpoint = $definition['common_checkpoint'] ?? ($definition['checkpoints']['at_q3'] ?? ($definition['checkpoints']['at_q2'] ?? null));

        $baseVersion = $this->createVersionFromGraph(
            $survey,
            1,
            $baseGraph,
            'published',
            true,
            [
                'seed_source' => 'conflict_policy_matrix',
                'matrix_type' => $surveyType,
                'scenario_id' => 'BASE',
                'scenario_title' => 'Base graph',
                'policy_class' => 'base',
                'checkpoint' => $defaultCheckpoint,
                'canonical_paths' => $definition['base_graph']['canonical_paths'] ?? [],
            ],
        );

        $scenarios = $definition['scenarios'] ?? [];
        foreach ($scenarios as $index => $scenario) {
            if (! is_array($scenario)) {
                continue;
            }

            $checkpoint = $scenario['checkpoint'] ?? null;
            if (is_string($checkpoint) && isset($definition['checkpoints'][$checkpoint])) {
                $checkpoint = $definition['checkpoints'][$checkpoint];
            }

            $scenarioGraph = $this->applyScenarioToGraph($baseGraph, $scenario);

            $this->createVersionFromGraph(
                $survey,
                $index + 2,
                $scenarioGraph,
                'draft',
                false,
                [
                    'seed_source' => 'conflict_policy_matrix',
                    'matrix_type' => $surveyType,
                    'scenario_id' => $scenario['id'] ?? 'UNKNOWN',
                    'scenario_title' => $scenario['title'] ?? 'Untitled scenario',
                    'policy_class' => $scenario['policy_class'] ?? null,
                    'checkpoint' => $checkpoint,
                    'mutations' => $scenario['mutation'] ?? [],
                    'expected' => $scenario['expected'] ?? [],
                ],
                $baseVersion->id,
            );
        }

        $survey->forceFill(['active_version_id' => $baseVersion->id])->save();
    }

    /**
     * @param  array<string, mixed>  $rawGraph
     * @return array{
     *   nodes: array<string, array<string, mixed>>,
     *   node_order: array<int, string>,
     *   edges: array<int, array<string, string>>
     * }
     */
    protected function normalizeGraph(array $rawGraph): array
    {
        $nodes = [];
        $nodeOrder = [];

        foreach (($rawGraph['nodes'] ?? []) as $node) {
            if (! is_array($node) || ! isset($node['stable_key'])) {
                continue;
            }

            $stableKey = (string) $node['stable_key'];
            $type = (string) ($node['type'] ?? 'multiple_choice');
            $normalizedOptions = [];

            foreach (($node['options'] ?? []) as $index => $option) {
                if (is_array($option)) {
                    $value = (string) ($option['value'] ?? '');
                    if ($value === '') {
                        continue;
                    }

                    $normalizedOptions[] = [
                        'value' => $value,
                        'label' => (string) ($option['label'] ?? $value),
                        'order_index' => (int) ($option['order_index'] ?? ($index + 1)),
                    ];
                    continue;
                }

                $value = trim((string) $option);
                if ($value === '') {
                    continue;
                }

                $normalizedOptions[] = [
                    'value' => $value,
                    'label' => $value,
                    'order_index' => $index + 1,
                ];
            }

            $nodes[$stableKey] = [
                'stable_key' => $stableKey,
                'type' => $type,
                'is_entry' => (bool) ($node['is_entry'] ?? false),
                'title' => (string) ($node['title'] ?? $this->defaultTitle($stableKey, $type)),
                'options' => $type === 'result' ? [] : $normalizedOptions,
            ];
            $nodeOrder[] = $stableKey;
        }

        $edges = [];
        foreach (($rawGraph['edges'] ?? []) as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $from = (string) ($edge['from'] ?? '');
            $to = (string) ($edge['to'] ?? '');
            $answer = (string) ($edge['answer'] ?? ($edge['value'] ?? ''));

            if ($from === '' || $to === '' || $answer === '') {
                continue;
            }

            $edges[] = [
                'from' => $from,
                'to' => $to,
                'answer' => $answer,
            ];
        }

        return [
            'nodes' => $nodes,
            'node_order' => $nodeOrder,
            'edges' => $edges,
        ];
    }

    /**
     * @param  array{
     *   nodes: array<string, array<string, mixed>>,
     *   node_order: array<int, string>,
     *   edges: array<int, array<string, string>>
     * }  $baseGraph
     * @param  array<string, mixed>  $scenario
     * @return array{
     *   nodes: array<string, array<string, mixed>>,
     *   node_order: array<int, string>,
     *   edges: array<int, array<string, string>>
     * }
     */
    protected function applyScenarioToGraph(array $baseGraph, array $scenario): array
    {
        $graph = $baseGraph;
        $mutations = $scenario['mutation'] ?? [];

        foreach ($mutations as $mutation) {
            if (! is_array($mutation)) {
                continue;
            }

            $operation = (string) ($mutation['op'] ?? '');

            switch ($operation) {
                case 'delete_edge':
                    $graph['edges'] = array_values(array_filter(
                        $graph['edges'],
                        function (array $edge) use ($mutation): bool {
                            if (($mutation['from'] ?? null) !== null && $edge['from'] !== (string) $mutation['from']) {
                                return true;
                            }

                            if (($mutation['answer'] ?? null) !== null && $edge['answer'] !== (string) $mutation['answer']) {
                                return true;
                            }

                            if (($mutation['to'] ?? null) !== null && $edge['to'] !== (string) $mutation['to']) {
                                return true;
                            }

                            return false;
                        }
                    ));
                    break;

                case 'add_edge':
                    $from = (string) ($mutation['from'] ?? '');
                    $to = (string) ($mutation['to'] ?? '');
                    $answer = (string) ($mutation['answer'] ?? '');

                    if ($from === '' || $to === '' || $answer === '') {
                        break;
                    }

                    if (! isset($graph['nodes'][$from]) || ! isset($graph['nodes'][$to])) {
                        break;
                    }

                    // One edge per source-option: replace existing mapping for the same source+answer.
                    $graph['edges'] = array_values(array_filter(
                        $graph['edges'],
                        fn (array $edge): bool => ! ($edge['from'] === $from && $edge['answer'] === $answer),
                    ));

                    $graph['edges'][] = [
                        'from' => $from,
                        'to' => $to,
                        'answer' => $answer,
                    ];
                    break;

                case 'delete_node':
                    $stableKey = (string) ($mutation['stable_key'] ?? '');
                    if ($stableKey === '' || ! isset($graph['nodes'][$stableKey])) {
                        break;
                    }

                    unset($graph['nodes'][$stableKey]);
                    $graph['node_order'] = array_values(array_filter(
                        $graph['node_order'],
                        fn (string $existing): bool => $existing !== $stableKey,
                    ));

                    $graph['edges'] = array_values(array_filter(
                        $graph['edges'],
                        fn (array $edge): bool => $edge['from'] !== $stableKey && $edge['to'] !== $stableKey,
                    ));
                    break;

                case 'add_option':
                    $questionKey = (string) ($mutation['question'] ?? '');
                    $value = (string) ($mutation['value'] ?? '');
                    if ($questionKey === '' || $value === '' || ! isset($graph['nodes'][$questionKey])) {
                        break;
                    }

                    $existingValues = array_map(
                        fn (array $option): string => (string) $option['value'],
                        $graph['nodes'][$questionKey]['options'] ?? [],
                    );

                    if (in_array($value, $existingValues, true)) {
                        break;
                    }

                    $graph['nodes'][$questionKey]['options'][] = [
                        'value' => $value,
                        'label' => (string) ($mutation['label'] ?? $value),
                        'order_index' => count($graph['nodes'][$questionKey]['options']) + 1,
                    ];
                    break;

                case 'update_option_label':
                    $questionKey = (string) ($mutation['question'] ?? '');
                    $value = (string) ($mutation['value'] ?? '');
                    $label = (string) ($mutation['label'] ?? '');
                    if ($questionKey === '' || $value === '' || $label === '' || ! isset($graph['nodes'][$questionKey])) {
                        break;
                    }

                    foreach ($graph['nodes'][$questionKey]['options'] as $idx => $option) {
                        if (($option['value'] ?? null) !== $value) {
                            continue;
                        }

                        $graph['nodes'][$questionKey]['options'][$idx]['label'] = $label;
                    }
                    break;

                case 'update_question_title':
                    $stableKey = (string) ($mutation['stable_key'] ?? '');
                    $title = (string) ($mutation['title'] ?? '');
                    if ($stableKey === '' || $title === '' || ! isset($graph['nodes'][$stableKey])) {
                        break;
                    }

                    $graph['nodes'][$stableKey]['title'] = $title;
                    break;

                case 'set_entry':
                    $entryKey = (string) ($mutation['stable_key'] ?? '');
                    if ($entryKey === '' || ! isset($graph['nodes'][$entryKey])) {
                        break;
                    }

                    foreach ($graph['nodes'] as $stableKey => $node) {
                        $graph['nodes'][$stableKey]['is_entry'] = $stableKey === $entryKey;
                    }
                    break;

                case 'set_order':
                    $requested = $mutation['ordered_stable_keys'] ?? [];
                    if (! is_array($requested) || empty($requested)) {
                        break;
                    }

                    $ordered = [];
                    foreach ($requested as $stableKey) {
                        $stableKey = (string) $stableKey;
                        if ($stableKey === '' || ! isset($graph['nodes'][$stableKey])) {
                            continue;
                        }
                        if (in_array($stableKey, $ordered, true)) {
                            continue;
                        }

                        $ordered[] = $stableKey;
                    }

                    $remaining = array_values(array_filter(
                        $graph['node_order'],
                        fn (string $stableKey): bool => isset($graph['nodes'][$stableKey]) && ! in_array($stableKey, $ordered, true),
                    ));

                    $graph['node_order'] = array_values(array_unique([...$ordered, ...$remaining]));
                    break;
            }
        }

        if (! collect($graph['nodes'])->contains(fn (array $node): bool => (bool) ($node['is_entry'] ?? false))) {
            $fallbackEntry = $graph['node_order'][0] ?? null;
            if ($fallbackEntry !== null && isset($graph['nodes'][$fallbackEntry])) {
                $graph['nodes'][$fallbackEntry]['is_entry'] = true;
            }
        }

        return $graph;
    }

    /**
     * @param  array{
     *   nodes: array<string, array<string, mixed>>,
     *   node_order: array<int, string>,
     *   edges: array<int, array<string, string>>
     * }  $graph
     * @param  array<string, mixed>  $schemaMeta
     */
    protected function createVersionFromGraph(
        Survey $survey,
        int $versionNumber,
        array $graph,
        string $status,
        bool $isActive,
        array $schemaMeta,
        ?int $baseVersionId = null,
    ): SurveyVersion {
        $version = SurveyVersion::query()->create([
            'survey_id' => $survey->id,
            'version_number' => $versionNumber,
            'status' => $status,
            'base_version_id' => $baseVersionId,
            'is_active' => $isActive,
            'published_at' => $status === 'published' ? now() : null,
            'schema_meta' => $schemaMeta,
        ]);

        $questionsByStable = [];

        foreach ($graph['node_order'] as $index => $stableKey) {
            $node = $graph['nodes'][$stableKey] ?? null;
            if (! $node) {
                continue;
            }

            $question = SurveyQuestion::query()->create([
                'survey_version_id' => $version->id,
                'stable_key' => $stableKey,
                'title' => (string) ($node['title'] ?? $this->defaultTitle($stableKey, (string) ($node['type'] ?? 'multiple_choice'))),
                'type' => (string) ($node['type'] ?? 'multiple_choice'),
                'is_entry' => (bool) ($node['is_entry'] ?? false),
                'order_index' => $index + 1,
            ]);

            foreach (($node['options'] ?? []) as $optionIndex => $option) {
                QuestionOption::query()->create([
                    'question_id' => $question->id,
                    'value' => (string) ($option['value'] ?? ''),
                    'label' => (string) ($option['label'] ?? ($option['value'] ?? '')),
                    'order_index' => (int) ($option['order_index'] ?? ($optionIndex + 1)),
                ]);
            }

            $questionsByStable[$stableKey] = $question;
        }

        foreach ($graph['edges'] as $priority => $edge) {
            $fromStable = (string) ($edge['from'] ?? '');
            $toStable = (string) ($edge['to'] ?? '');
            $answer = (string) ($edge['answer'] ?? '');

            $fromQuestion = $questionsByStable[$fromStable] ?? null;
            $toQuestion = $questionsByStable[$toStable] ?? null;

            if (! $fromQuestion || ! $toQuestion || $answer === '') {
                continue;
            }

            QuestionEdge::query()->create([
                'survey_version_id' => $version->id,
                'from_question_id' => $fromQuestion->id,
                'to_question_id' => $toQuestion->id,
                'condition_type' => 'answer',
                'condition_operator' => 'equals',
                'condition_value' => $answer,
                'priority' => $priority + 1,
            ]);
        }

        return $version;
    }

    protected function defaultTitle(string $stableKey, string $type): string
    {
        if ($type === 'result' && str_starts_with($stableKey, 'R_')) {
            $label = str_replace('_', ' ', substr($stableKey, 2));
            return 'You are '.ucwords(strtolower($label));
        }

        return strtoupper($stableKey);
    }
}
