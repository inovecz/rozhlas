<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gsm_whitelist_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('number')->unique();
            $table->string('label')->nullable();
            $table->string('priority')->default('normal');
            $table->timestamps();
        });

        Schema::create('gsm_call_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('caller')->index();
            $table->string('status')->default('initiated');
            $table->boolean('authorised')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('gsm_pin_verifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('session_id');
            $table->string('pin');
            $table->boolean('verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();

            $table->foreign('session_id')->references('id')->on('gsm_call_sessions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsm_pin_verifications');
        Schema::dropIfExists('gsm_call_sessions');
        Schema::dropIfExists('gsm_whitelist_entries');
    }
};
