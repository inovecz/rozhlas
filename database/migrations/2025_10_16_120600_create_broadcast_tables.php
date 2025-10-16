<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('broadcast_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source');
            $table->json('route')->nullable();
            $table->json('zones')->nullable();
            $table->json('options')->nullable();
            $table->string('status')->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->string('stop_reason')->nullable();
            $table->json('python_response')->nullable();
            $table->timestamps();
        });

        Schema::create('broadcast_playlists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status')->default('queued');
            $table->json('route')->nullable();
            $table->json('zones')->nullable();
            $table->json('options')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
        });

        Schema::create('broadcast_playlist_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('playlist_id');
            $table->unsignedInteger('position');
            $table->string('recording_id');
            $table->integer('duration_seconds')->nullable();
            $table->decimal('gain', 5, 2)->nullable();
            $table->integer('gap_ms')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('playlist_id')->references('id')->on('broadcast_playlists')->cascadeOnDelete();
        });

        Schema::create('stream_telemetry_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->uuid('session_id')->nullable();
            $table->uuid('playlist_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stream_telemetry_entries');
        Schema::dropIfExists('broadcast_playlist_items');
        Schema::dropIfExists('broadcast_playlists');
        Schema::dropIfExists('broadcast_sessions');
    }
};
