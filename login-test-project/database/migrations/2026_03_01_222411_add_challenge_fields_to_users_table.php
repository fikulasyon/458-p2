<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('challenge_attempts')->default(0);
            $table->timestamp('challenge_locked_until')->nullable();

            $table->string('challenge_otp_hash')->nullable();
            $table->timestamp('challenge_otp_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'challenge_attempts',
                'challenge_locked_until',
                'challenge_otp_hash',
                'challenge_otp_expires_at',
            ]);
        });
    }
};