<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssh_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['file_path', 'string'])->default('file_path');
            $table->text('value'); // encrypted: file path or key content
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssh_keys');
    }
};
