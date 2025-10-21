<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('jsvv_audio')
            ->where('symbol', 'L')
            ->update(['name' => 'Rezerva 3']);
    }

    public function down(): void
    {
        DB::table('jsvv_audio')
            ->where('symbol', 'L')
            ->update(['name' => 'Rezerva 2']);
    }
};
