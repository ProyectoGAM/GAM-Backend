<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('report_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('normalized_name', 160);
            $table->string('source_key', 100);
            $table->string('definition_version', 40);
            $table->jsonb('configuration');
            $table->timestamps();

            $table->unique(['user_id', 'normalized_name']);
            $table->index(['user_id', 'source_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_presets');
    }
};
