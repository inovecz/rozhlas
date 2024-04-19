<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->dateTime('scheduled_at');
            $table->integer('duration')->nullable();
            $table->dateTime('end_at')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->boolean('is_repeating')->default(false);
            $table->foreignId('intro_id')->nullable()->constrained('files');
            $table->foreignId('opening_id')->nullable()->constrained('files');
            $table->json('common_ids');
            $table->foreignId('closing_id')->nullable()->constrained('files');
            $table->foreignId('outro_id')->nullable()->constrained('files');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
