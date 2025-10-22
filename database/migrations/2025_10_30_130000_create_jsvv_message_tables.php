<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('jsvv_messages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedSmallInteger('network_id');
            $table->unsignedSmallInteger('vyc_id');
            $table->string('kpps_address', 32);
            $table->unsignedInteger('operator_id')->nullable();
            $table->string('type', 32);
            $table->string('command', 64);
            $table->json('params');
            $table->string('priority', 4);
            $table->unsignedBigInteger('payload_timestamp');
            $table->timestampTz('received_at');
            $table->longText('raw_message');
            $table->string('status', 24)->default('NEW');
            $table->string('dedup_key', 128);
            $table->smallInteger('artisan_exit_code')->nullable();
            $table->json('meta')->nullable();
            $table->timestampsTz();

            $table->unique('dedup_key', 'jsvv_messages_dedup_unique');
            $table->index(['network_id', 'vyc_id', 'kpps_address', 'payload_timestamp', 'priority'], 'jsvv_messages_lookup_index');
            $table->index(['type', 'command'], 'jsvv_messages_type_command_index');
            $table->index(['status', 'created_at'], 'jsvv_messages_status_index');
        });

        Schema::create('control_channel_commands', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('command', 32);
            $table->string('state_before', 24)->nullable();
            $table->string('state_after', 24)->nullable();
            $table->string('reason', 128)->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('result', 24)->nullable();
            $table->json('payload')->nullable();
            $table->timestampTz('issued_at')->useCurrent();
            $table->timestampsTz();

            $table->foreign('message_id')->references('id')->on('jsvv_messages')->nullOnDelete();
            $table->index(['command', 'issued_at'], 'control_channel_command_index');
        });

        Schema::table('jsvv_events', function (Blueprint $table): void {
            if (!Schema::hasColumn('jsvv_events', 'message_id')) {
                $table->unsignedBigInteger('message_id')->nullable()->after('id');
            }
            if (!Schema::hasColumn('jsvv_events', 'event')) {
                $table->string('event', 128)->nullable()->after('message_id');
            }
            if (!Schema::hasColumn('jsvv_events', 'data')) {
                $table->json('data')->nullable()->after('event');
            }
            if (!Schema::hasColumn('jsvv_events', 'created_at')) {
                $table->timestamps();
            }

            $table->foreign('message_id')->references('id')->on('jsvv_messages')->nullOnDelete();
            $table->index(['message_id', 'event'], 'jsvv_events_message_event_index');
        });
    }

    public function down(): void
    {
        Schema::table('jsvv_events', function (Blueprint $table): void {
            if (Schema::hasColumn('jsvv_events', 'message_id')) {
                $table->dropForeign(['message_id']);
                $table->dropColumn(['message_id']);
            }
            if (Schema::hasColumn('jsvv_events', 'event')) {
                $table->dropColumn(['event']);
            }
            if (Schema::hasColumn('jsvv_events', 'data')) {
                $table->dropColumn(['data']);
            }
            if (Schema::hasColumn('jsvv_events', 'created_at') && Schema::hasColumn('jsvv_events', 'updated_at')) {
                $table->dropColumn(['created_at', 'updated_at']);
            }
        });

        Schema::dropIfExists('control_channel_commands');
        Schema::dropIfExists('jsvv_messages');
    }
};
