<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('locations', static function (Blueprint $table): void {
            $table->unsignedInteger('modbus_address')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('locations', static function (Blueprint $table): void {
            $table->dropColumn('modbus_address');
        });
    }
};
