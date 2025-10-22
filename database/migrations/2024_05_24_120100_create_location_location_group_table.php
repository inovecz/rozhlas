<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('location_location_group', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_group_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['location_id', 'location_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_location_group');
    }
};
