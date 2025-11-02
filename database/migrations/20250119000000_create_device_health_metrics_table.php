<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_health_metrics', function (Blueprint $table): void {
            $table->string('metric')->primary();
            $table->string('state')->default('unknown');
            $table->json('meta')->nullable();
            $table->timestamp('last_fault_notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_health_metrics');
    }
};
