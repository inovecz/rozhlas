<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('location_groups', static function (Blueprint $table): void {
            $table->unsignedInteger('modbus_group_address')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('location_groups', static function (Blueprint $table): void {
            $table->dropColumn('modbus_group_address');
        });
    }
};

