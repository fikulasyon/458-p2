<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('active_version_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('survey_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status')->default('draft');
            $table->foreignId('base_version_id')->nullable()->constrained('survey_versions')->nullOnDelete();
            $table->boolean('is_active')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->json('schema_meta')->nullable();
            $table->timestamps();

            $table->unique(['survey_id', 'version_number']);
            $table->index(['survey_id', 'status']);
        });

        Schema::create('survey_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_version_id')->constrained('survey_versions')->cascadeOnDelete();
            $table->string('stable_key');
            $table->string('title');
            $table->string('type');
            $table->boolean('is_entry')->default(false);
            $table->integer('order_index')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['survey_version_id', 'stable_key']);
            $table->index(['survey_version_id', 'order_index']);
        });

        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('survey_questions')->cascadeOnDelete();
            $table->string('value');
            $table->string('label');
            $table->integer('order_index')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['question_id', 'value']);
            $table->index(['question_id', 'order_index']);
        });

        Schema::create('question_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_version_id')->constrained('survey_versions')->cascadeOnDelete();
            $table->foreignId('from_question_id')->constrained('survey_questions')->cascadeOnDelete();
            $table->foreignId('to_question_id')->constrained('survey_questions')->cascadeOnDelete();
            $table->string('condition_type')->default('answer');
            $table->string('condition_operator')->default('equals');
            $table->text('condition_value')->nullable();
            $table->integer('priority')->default(0);
            $table->timestamps();

            $table->index(['survey_version_id', 'from_question_id']);
            $table->index(['survey_version_id', 'to_question_id']);
        });

        Schema::create('survey_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('started_version_id')->constrained('survey_versions')->cascadeOnDelete();
            $table->foreignId('current_version_id')->nullable()->constrained('survey_versions')->nullOnDelete();
            $table->foreignId('current_question_id')->nullable()->constrained('survey_questions')->nullOnDelete();
            $table->string('status')->default('in_progress');
            $table->string('stable_node_key')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'survey_id', 'status']);
        });

        Schema::create('survey_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('survey_sessions')->cascadeOnDelete();
            $table->string('question_stable_key');
            $table->foreignId('question_id')->nullable()->constrained('survey_questions')->nullOnDelete();
            $table->text('answer_value')->nullable();
            $table->foreignId('valid_under_version_id')->constrained('survey_versions')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['session_id', 'question_stable_key']);
            $table->index(['session_id', 'is_active']);
        });

        Schema::create('survey_conflict_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('survey_sessions')->cascadeOnDelete();
            $table->foreignId('old_version_id')->constrained('survey_versions')->cascadeOnDelete();
            $table->foreignId('new_version_id')->constrained('survey_versions')->cascadeOnDelete();
            $table->string('conflict_type');
            $table->string('recovery_strategy');
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('survey_conflict_logs');
        Schema::dropIfExists('survey_answers');
        Schema::dropIfExists('survey_sessions');
        Schema::dropIfExists('question_edges');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('survey_questions');
        Schema::dropIfExists('survey_versions');
        Schema::dropIfExists('surveys');
    }
};
