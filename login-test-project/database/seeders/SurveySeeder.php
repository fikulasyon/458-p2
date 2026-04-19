<?php

namespace Database\Seeders;

use App\Models\QuestionEdge;
use App\Models\QuestionOption;
use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\SurveyVersion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SurveySeeder extends Seeder
{
    /**
     * Seed the application's survey baseline data.
     */
    public function run(): void
    {
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

        $survey = Survey::query()->firstOrCreate(
            ['title' => 'Dream Job Finder'],
            [
                'description' => 'Adaptive survey that suggests a work style path.',
                'created_by' => $admin->id,
            ],
        );

        $publishedVersion = SurveyVersion::query()->firstOrCreate(
            [
                'survey_id' => $survey->id,
                'version_number' => 1,
            ],
            [
                'status' => 'published',
                'is_active' => true,
                'published_at' => now(),
                'schema_meta' => ['seeded' => true],
            ],
        );

        $q1 = SurveyQuestion::query()->firstOrCreate(
            [
                'survey_version_id' => $publishedVersion->id,
                'stable_key' => 'q_people',
            ],
            [
                'title' => 'Do you enjoy working with people?',
                'type' => 'boolean',
                'is_entry' => true,
                'order_index' => 1,
            ],
        );

        $q2 = SurveyQuestion::query()->firstOrCreate(
            [
                'survey_version_id' => $publishedVersion->id,
                'stable_key' => 'q_analytical',
            ],
            [
                'title' => 'Do you like analytical tasks?',
                'type' => 'boolean',
                'is_entry' => false,
                'order_index' => 2,
            ],
        );

        $q3 = SurveyQuestion::query()->firstOrCreate(
            [
                'survey_version_id' => $publishedVersion->id,
                'stable_key' => 'q_work_style',
            ],
            [
                'title' => 'Preferred work style?',
                'type' => 'multiple_choice',
                'is_entry' => false,
                'order_index' => 3,
            ],
        );

        $q4 = SurveyQuestion::query()->firstOrCreate(
            [
                'survey_version_id' => $publishedVersion->id,
                'stable_key' => 'q_recommendation',
            ],
            [
                'title' => 'Recommended career path',
                'type' => 'text',
                'is_entry' => false,
                'order_index' => 4,
                'metadata' => ['readonly' => true],
            ],
        );

        $options = [
            ['value' => 'remote', 'label' => 'Remote-first'],
            ['value' => 'hybrid', 'label' => 'Hybrid'],
            ['value' => 'office', 'label' => 'Office-based'],
        ];

        foreach ($options as $index => $option) {
            QuestionOption::query()->firstOrCreate(
                [
                    'question_id' => $q3->id,
                    'value' => $option['value'],
                ],
                [
                    'label' => $option['label'],
                    'order_index' => $index + 1,
                ],
            );
        }

        $edges = [
            [
                'from_question_id' => $q1->id,
                'to_question_id' => $q2->id,
                'condition_value' => 'true',
                'priority' => 1,
            ],
            [
                'from_question_id' => $q1->id,
                'to_question_id' => $q3->id,
                'condition_value' => 'false',
                'priority' => 2,
            ],
            [
                'from_question_id' => $q2->id,
                'to_question_id' => $q3->id,
                'condition_value' => 'true',
                'priority' => 3,
            ],
            [
                'from_question_id' => $q2->id,
                'to_question_id' => $q4->id,
                'condition_value' => 'true',
                'priority' => 4,
            ],
            [
                'from_question_id' => $q3->id,
                'to_question_id' => $q4->id,
                'condition_operator' => 'in',
                'condition_value' => json_encode(['remote', 'hybrid', 'office']),
                'priority' => 5,
            ],
        ];

        foreach ($edges as $edge) {
            QuestionEdge::query()->firstOrCreate(
                [
                    'survey_version_id' => $publishedVersion->id,
                    'from_question_id' => $edge['from_question_id'],
                    'to_question_id' => $edge['to_question_id'],
                    'condition_operator' => $edge['condition_operator'] ?? 'equals',
                    'condition_value' => $edge['condition_value'],
                ],
                [
                    'condition_type' => 'answer',
                    'priority' => $edge['priority'],
                ],
            );
        }

        $survey->forceFill(['active_version_id' => $publishedVersion->id])->save();
    }
}
