<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // e.g. local, staging, production
            $table->boolean('is_local')->default(false);

            // Site
            $table->string('vhost')->nullable();
            $table->string('wordpress_path');

            // Database
            $table->string('db_name')->nullable();
            $table->string('db_user')->nullable();
            $table->text('db_password')->nullable(); // encrypted
            $table->string('db_host')->default('127.0.0.1');
            $table->unsignedSmallInteger('db_port')->default(3306);
            $table->string('db_prefix')->default('wp_');
            $table->string('mysqldump_options')->nullable();

            // SSH
            $table->string('ssh_host')->nullable();
            $table->string('ssh_user')->nullable();
            $table->unsignedSmallInteger('ssh_port')->default(22);
            $table->text('ssh_password')->nullable(); // encrypted
            $table->foreignId('ssh_key_id')->nullable()->constrained('ssh_keys')->nullOnDelete();

            // Rsync
            $table->string('rsync_options')->nullable();
            $table->json('exclude')->nullable();

            $table->timestamps();

            $table->unique(['site_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environments');
    }
};
