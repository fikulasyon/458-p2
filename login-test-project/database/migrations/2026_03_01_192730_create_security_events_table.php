<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('event_type'); // login_failed, login_success, risk_scored, account_locked, llm_decision, challenged, etc.
            $table->string('ip')->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->unsignedSmallInteger('risk_score')->nullable(); // 0..100
            $table->json('reasons')->nullable(); // ["new_ip", "ua_change", ...]
            $table->json('meta')->nullable();    // arbitrary payload

            $table->timestamps();

            $table->index(['user_id', 'event_type']);
            $table->index(['event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};