<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('jsvv_sequences', function (Blueprint $table): void {
            $table->decimal('estimated_duration_seconds', 10, 2)->nullable()->after('options');
            $table->decimal('actual_duration_seconds', 10, 2)->nullable()->after('estimated_duration_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('jsvv_sequences', function (Blueprint $table): void {
            $table->dropColumn(['estimated_duration_seconds', 'actual_duration_seconds']);
        });
    }
};
