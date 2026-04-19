<?php

namespace App\Services;

use App\Models\SurveyVersion;

class SurveyVisibilityEngine
{
    public function __construct(
        protected SurveyGraphBuilder $graphBuilder,
        protected SurveyGraphValidator $graphValidator,
    ) {
    }

    /**
     * @param  SurveyVersion|int  $version
     * @param  array<string, mixed>  $answersByStableKey
     * @return array{
     *   visible_question_ids:array<int, int>,
     *   visible_stable_keys:array<int, string>,
     *   hidden_stable_keys:array<int, string>,
     *   errors:array<int, array{code:string, message:string}>,
     *   topological_order:array<int, int>
     * }
     */
    public function calculate(SurveyVersion|int $version, array $answersByStableKey = []): array
    {
        $graph = $this->graphBuilder->build($version);
        $validation = $this->graphValidator->validateGraph($graph);

        $topologicalOrder = $validation['topological_order'];
        if (empty($topologicalOrder)) {
            $topologicalOrder = $graph['questions']->pluck('id')->all();
        }

        $visible = [];
        foreach ($topologicalOrder as $questionId) {
            $question = $graph['questions_by_id'][$questionId] ?? null;
            if (! $question) {
                continue;
            }

            if ($question->is_entry) {
                $visible[$questionId] = true;
                continue;
            }

            $visible[$questionId] = false;
            foreach ($graph['incoming'][$questionId] ?? [] as $edge) {
                $parentId = $edge['from_question_id'];
                $parentQuestion = $graph['questions_by_id'][$parentId] ?? null;

                if (! $parentQuestion || empty($visible[$parentId])) {
                    continue;
                }

                $parentAnswer = $answersByStableKey[$parentQuestion->stable_key] ?? null;
                if ($this->edgeConditionPasses($edge['condition_operator'], $edge['condition_value'], $parentAnswer)) {
                    $visible[$questionId] = true;
                    break;
                }
            }
        }

        $visibleQuestionIds = [];
        $visibleStableKeys = [];
        $hiddenStableKeys = [];

        foreach ($graph['questions'] as $question) {
            if (! empty($visible[$question->id])) {
                $visibleQuestionIds[] = $question->id;
                $visibleStableKeys[] = $question->stable_key;
            } else {
                $hiddenStableKeys[] = $question->stable_key;
            }
        }

        return [
            'visible_question_ids' => $visibleQuestionIds,
            'visible_stable_keys' => $visibleStableKeys,
            'hidden_stable_keys' => $hiddenStableKeys,
            'errors' => $validation['errors'],
            'topological_order' => $topologicalOrder,
        ];
    }

    protected function edgeConditionPasses(string $operator, mixed $conditionValue, mixed $parentAnswer): bool
    {
        if ($operator === 'always') {
            return true;
        }

        if ($parentAnswer === null) {
            return false;
        }

        $actual = $this->normalizeValue($parentAnswer);
        $expected = $this->normalizeValue($conditionValue);

        return match ($operator) {
            'equals' => $actual === $expected,
            'not_equals' => $actual !== $expected,
            'in' => $this->inConditionPasses($actual, $conditionValue),
            'contains' => $this->containsConditionPasses($actual, $expected),
            'gt' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            'gte' => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
            'lt' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            'lte' => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
            default => false,
        };
    }

    protected function inConditionPasses(mixed $actual, mixed $rawExpected): bool
    {
        $expectedValues = $this->normalizeList($rawExpected);

        if (is_array($actual)) {
            foreach ($actual as $value) {
                if (in_array($this->normalizeValue($value), $expectedValues, true)) {
                    return true;
                }
            }

            return false;
        }

        return in_array($actual, $expectedValues, true);
    }

    protected function containsConditionPasses(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            return in_array($expected, array_map(fn ($value) => $this->normalizeValue($value), $actual), true);
        }

        return str_contains((string) $actual, (string) $expected);
    }

    protected function normalizeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->normalizeValue($item), $value);
        }

        if (! is_string($value)) {
            return [$this->normalizeValue($value)];
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return array_map(fn ($item) => $this->normalizeValue($item), $decoded);
        }

        if (str_contains($value, ',')) {
            return array_map(fn ($item) => $this->normalizeValue(trim($item)), explode(',', $value));
        }

        return [$this->normalizeValue($value)];
    }

    protected function normalizeValue(mixed $value): mixed
    {
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return array_map(fn ($item) => $this->normalizeValue($item), $value);
        }

        $stringValue = trim((string) $value);
        $lower = strtolower($stringValue);

        if ($lower === 'true') {
            return true;
        }

        if ($lower === 'false') {
            return false;
        }

        if (is_numeric($stringValue)) {
            return str_contains($stringValue, '.') ? (float) $stringValue : (int) $stringValue;
        }

        return $stringValue;
    }
}
