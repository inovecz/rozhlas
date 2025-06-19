<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('location_groups', static function (Blueprint $table) {
            $defaultSubtoneData = [
                'listen' => [],
                'record' => [],
            ];

            $defaultTiming = [
                'total' => ['start' => 0, 'end' => 0],
                'ptt' => ['start' => 0, 'end' => 0],
                'subtone' => ['start' => 0, 'end' => 0],
                'file' => ['start' => 0, 'end' => 0],
                'output_1' => ['start' => 0, 'end' => 0],
                'output_2' => ['start' => 0, 'end' => 0],
                'output_3' => ['start' => 0, 'end' => 0],
                'output_4' => ['start' => 0, 'end' => 0],
                'output_5' => ['start' => 0, 'end' => 0],
                'output_6' => ['start' => 0, 'end' => 0],
                'output_7' => ['start' => 0, 'end' => 0],
                'output_8' => ['start' => 0, 'end' => 0],
                'output_9' => ['start' => 0, 'end' => 0],
                'output_10' => ['start' => 0, 'end' => 0],
                'output_11' => ['start' => 0, 'end' => 0],
                'relay_1' => ['start' => 0, 'end' => 0],
                'relay_2' => ['start' => 0, 'end' => 0],
                'relay_3' => ['start' => 0, 'end' => 0],
                'relay_4' => ['start' => 0, 'end' => 0],
                'relay_5' => ['start' => 0, 'end' => 0],
            ];

            $table->id();
            $table->string('name');
            $table->boolean('is_hidden')->default(false);
            $table->enum('subtone_type', ['NONE', 'A16', 'CTCSS_38', 'CTCSS_39', 'CTCSS_47', 'CTCSS_38N', 'CTCSS_32', 'CTCSS_EIA', 'CTCSS_ALINCO', 'CTCSS_MOTOROLA', 'DCS'])->default('NONE');
            $table->json('subtone_data')->default(json_encode($defaultSubtoneData));
            $table->foreignId('init_audio_id')->nullable()->constrained('files')->nullOnDelete();
            $table->foreignId('exit_audio_id')->nullable()->constrained('files')->nullOnDelete();
            $table->json('timing')->default(json_encode($defaultTiming));
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_groups');
    }
};
