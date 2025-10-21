<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('schedules', static function (Blueprint $table) {
            $table->unsignedInteger('repeat_count')->nullable()->after('is_repeating');
            $table->unsignedInteger('repeat_interval_value')->nullable()->after('repeat_count');
            $table->string('repeat_interval_unit', 50)->nullable()->after('repeat_interval_value');
            $table->json('repeat_interval_meta')->nullable()->after('repeat_interval_unit');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', static function (Blueprint $table) {
            $table->dropColumn([
                'repeat_count',
                'repeat_interval_value',
                'repeat_interval_unit',
                'repeat_interval_meta',
            ]);
        });
    }
};
