<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_environment_id')->constrained('environments')->cascadeOnDelete();
            $table->foreignId('to_environment_id')->constrained('environments')->cascadeOnDelete();
            $table->enum('direction', ['push', 'pull']);
            $table->json('scope'); // ["db", "files", "core", "all"]
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->longText('output')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
