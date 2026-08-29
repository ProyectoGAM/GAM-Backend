<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('locality_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('normalized_name', 120);
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 10, 6);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['locality_id', 'normalized_name']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('poultry_houses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_unit_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('normalized_name', 120);
            $table->unsignedInteger('bird_capacity');
            $table->string('status', 30)->default('operational');
            $table->timestamps();

            $table->unique(['production_unit_id', 'normalized_name']);
            $table->index(['production_unit_id', 'status', 'name']);
        });

        DB::statement('ALTER TABLE production_units ADD CONSTRAINT production_units_latitude_check CHECK (latitude BETWEEN -90 AND 90)');
        DB::statement('ALTER TABLE production_units ADD CONSTRAINT production_units_longitude_check CHECK (longitude BETWEEN -180 AND 180)');
        DB::statement("ALTER TABLE production_units ADD CONSTRAINT production_units_status_check CHECK (status IN ('active', 'inactive'))");
        DB::statement('ALTER TABLE poultry_houses ADD CONSTRAINT poultry_houses_bird_capacity_check CHECK (bird_capacity > 0)');
        DB::statement("ALTER TABLE poultry_houses ADD CONSTRAINT poultry_houses_status_check CHECK (status IN ('operational', 'maintenance', 'out_of_service', 'inactive'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('poultry_houses');
        Schema::dropIfExists('production_units');
    }
};
