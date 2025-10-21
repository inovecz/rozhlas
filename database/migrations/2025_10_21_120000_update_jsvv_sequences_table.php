<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('jsvv_sequences', function (Blueprint $table): void {
            $table->timestamp('queued_at')->nullable()->after('status');
            $table->timestamp('failed_at')->nullable()->after('completed_at');
            $table->text('error_message')->nullable()->after('failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('jsvv_sequences', function (Blueprint $table): void {
            $table->dropColumn(['queued_at', 'failed_at', 'error_message']);
        });
    }
};
