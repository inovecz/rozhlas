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
        Schema::create('scheduled_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->dateTime('scheduled_at');
            $table->foreignId('intro_id')->nullable()->constrained('files')->onDelete('restrict');
            $table->foreignId('recording_id')->nullable()->constrained('files')->onDelete('restrict');
            $table->foreignId('outro_id')->nullable()->constrained('files')->onDelete('restrict');
            $table->boolean('is_repeating')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_broadcasts');
    }
};
