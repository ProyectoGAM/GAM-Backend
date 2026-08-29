<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('normalized_name', 120)->unique();
            $table->timestamps();
        });

        Schema::create('localities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('normalized_name', 120);
            $table->timestamps();

            $table->unique(['department_id', 'normalized_name']);
            $table->index(['department_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('localities');
        Schema::dropIfExists('departments');
    }
};
