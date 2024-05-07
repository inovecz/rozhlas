<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('contacts', static function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('surname');
            $table->string('position')->nullable();
            $table->string('email')->nullable();
            $table->boolean('has_info_email_allowed')->default(false);
            $table->string('phone')->nullable();
            $table->boolean('has_info_sms_allowed')->default(false);
            $table->timestamps();
        });

        Schema::create('contact_groups', static function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('contact_contact_group', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_group_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('contact_groups');
        Schema::dropIfExists('contact_contact_group');
    }
};
