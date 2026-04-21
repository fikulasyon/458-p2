<?php

use App\Models\QuestionEdge;
use App\Models\Survey;
use App\Models\SurveyVersion;
use Database\Seeders\ConflictPolicyMatrixSeeder;

it('seeds architect-visible versions for the conflict-policy matrix', function () {
    $matrix = require base_path('tests/Support/ConflictPolicyMatrix.php');
    $this->seed(ConflictPolicyMatrixSeeder::class);

    foreach (['multiple_choice', 'rating', 'open_ended'] as $type) {
        $definition = $matrix[$type];
        $scenarioCount = count($definition['scenarios'] ?? []);
        $title = $definition['seed_survey_title'];

        $survey = Survey::query()
            ->where('title', $title)
            ->first();

        expect($survey)->not->toBeNull();
        expect($survey->survey_type)->toBe($type)
            ->and($survey->versions()->count())->toBe($scenarioCount + 1);

        $baseVersion = SurveyVersion::query()
            ->where('survey_id', $survey->id)
            ->where('version_number', 1)
            ->first();

        expect($baseVersion)->not->toBeNull();
        expect($baseVersion->status)->toBe('published')
            ->and($baseVersion->is_active)->toBeTrue()
            ->and($survey->active_version_id)->toBe($baseVersion->id);
    }

    $multipleChoiceSurvey = Survey::query()
        ->where('title', $matrix['multiple_choice']['seed_survey_title'])
        ->first();

    $scenarioVersion = SurveyVersion::query()
        ->where('survey_id', $multipleChoiceSurvey->id)
        ->get()
        ->first(fn (SurveyVersion $version): bool => data_get($version->schema_meta, 'scenario_id') === 'MC_RB_03');

    expect($scenarioVersion)->not->toBeNull();
    expect($scenarioVersion->status)->toBe('draft');

    $q2Id = $scenarioVersion->questions()->where('stable_key', 'Q2')->value('id');
    $hasRemovedEdge = QuestionEdge::query()
        ->where('survey_version_id', $scenarioVersion->id)
        ->where('from_question_id', $q2Id)
        ->where('condition_operator', 'equals')
        ->where('condition_value', 'B')
        ->whereHas('toQuestion', fn ($query) => $query->where('stable_key', 'Q7'))
        ->exists();

    expect($hasRemovedEdge)->toBeFalse();
});
