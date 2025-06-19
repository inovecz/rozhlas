<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('jsvv_alarms', static function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->char('sequence_1', 1)->nullable();
            $table->char('sequence_2', 1)->nullable();
            $table->char('sequence_3', 1)->nullable();
            $table->char('sequence_4', 1)->nullable();
            $table->unsignedTinyInteger('button')->nullable();
            $table->unsignedTinyInteger('mobile_button')->nullable();
            $table->timestamps();

            $table->foreign('sequence_1')->references('symbol')->on('jsvv_audio');
            $table->foreign('sequence_2')->references('symbol')->on('jsvv_audio');
            $table->foreign('sequence_3')->references('symbol')->on('jsvv_audio');
            $table->foreign('sequence_4')->references('symbol')->on('jsvv_audio');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jsvv_alarms');
    }
};
