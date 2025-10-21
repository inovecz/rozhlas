<?php

declare(strict_types=1);

use App\Enums\LocationStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('locations', static function (Blueprint $table) {
            $table->unsignedSmallInteger('bidirectional_address')->nullable()->after('modbus_address');
            $table->unsignedSmallInteger('private_receiver_address')->nullable()->after('bidirectional_address');
            $table->json('components')->nullable()->after('private_receiver_address');
            $table->string('status', 20)->default(LocationStatusEnum::OK->value)->after('components');
        });
    }

    public function down(): void
    {
        Schema::table('locations', static function (Blueprint $table) {
            $table->dropColumn([
                'bidirectional_address',
                'private_receiver_address',
                'components',
                'status',
            ]);
        });
    }
};
