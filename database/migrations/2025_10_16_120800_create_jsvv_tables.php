<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('jsvv_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->json('items');
            $table->json('options')->nullable();
            $table->string('priority')->nullable();
            $table->string('status')->default('planned');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('triggered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
        });

        Schema::create('jsvv_sequence_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('sequence_id');
            $table->unsignedInteger('position');
            $table->string('category');
            $table->unsignedInteger('slot');
            $table->string('voice')->nullable();
            $table->unsignedInteger('repeat')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('sequence_id')->references('id')->on('jsvv_sequences')->cascadeOnDelete();
        });

        Schema::create('jsvv_events', function (Blueprint $table): void {
            $table->id();
            $table->string('command')->nullable();
            $table->string('mid')->nullable();
            $table->string('priority')->nullable();
            $table->boolean('duplicate')->default(false);
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jsvv_events');
        Schema::dropIfExists('jsvv_sequence_items');
        Schema::dropIfExists('jsvv_sequences');
    }
};
