<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('files', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('users');
            $table->enum('type', ['COMMON', 'RECORDING'])->default('COMMON');
            $table->enum('subtype', ['COMMON', 'OPENING', 'CLOSING', 'INTRO', 'OUTRO', 'OTHER',])->default('OTHER');
            $table->string('name');
            $table->uuid('filename');
            $table->string('path');
            $table->string('extension');
            $table->string('mime_type');
            $table->unsignedBigInteger('size')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
