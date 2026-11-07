<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_configurations', function (Blueprint $table): void {
            $table->foreignId('production_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 80);
            $table->jsonb('settings');
        });

        DB::statement('CREATE UNIQUE INDEX alert_configurations_scope_type_unique ON alert_configurations (production_unit_id, type) NULLS NOT DISTINCT');
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_configurations');
    }
};
