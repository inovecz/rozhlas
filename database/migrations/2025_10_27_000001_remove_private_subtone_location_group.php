<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('location_groups')
            ->where('name', 'Lokalita pro privátní subtóny')
            ->delete();
    }

    public function down(): void
    {
        // No automatic rollback – the seed can be re-run if needed.
    }
};

