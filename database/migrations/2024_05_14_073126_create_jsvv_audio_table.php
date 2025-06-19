<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('jsvv_audio', static function (Blueprint $table) {
            $table->char('symbol', 1)->primary();
            $table->string('name');
            $table->enum('group', ['SIREN', 'GONG', 'VERBAL', 'AUDIO']);
            $table->enum('type', ['FILE', 'SOURCE'])->default('FILE');
            $table->foreignId('file_id')->nullable();
            $table->enum('source', ['INPUT_1', 'INPUT_2', 'INPUT_3', 'INPUT_4', 'INPUT_5', 'INPUT_6', 'INPUT_7', 'INPUT_8', 'FM', 'MIC'])->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jsvv_audio');
    }
};
