<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('location_groups', static function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_hidden')->default(false);
            $table->string('subtone_type')->default('none');
            $table->json('subtone_data')->default('{"listen":[],"record":[]}');
            $table->foreignId('init_audio_id')->nullable()->constrained('files')->nullOnDelete();
            $table->foreignId('exit_audio_id')->nullable()->constrained('files')->nullOnDelete();
            $table->json('timing')->nullable();
            $table->timestamps();
        });

        Schema::create('locations', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->enum('type', ['CENTRAL', 'NEST'])->default('NEST');
            $table->double('longitude', 11, 8);
            $table->double('latitude', 10, 8);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
