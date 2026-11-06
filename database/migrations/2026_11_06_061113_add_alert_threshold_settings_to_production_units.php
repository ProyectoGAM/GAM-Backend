<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_units', function (Blueprint $table): void {
            $table->jsonb('alert_timing_thresholds')->nullable();
            $table->unsignedSmallInteger('feed_coverage_low_days')->nullable();
        });

        DB::statement('ALTER TABLE production_units ADD CONSTRAINT production_units_feed_coverage_low_check CHECK (feed_coverage_low_days IS NULL OR feed_coverage_low_days > feed_coverage_warning_days AND feed_coverage_low_days <= 365)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE production_units DROP CONSTRAINT production_units_feed_coverage_low_check');
        Schema::table('production_units', function (Blueprint $table): void {
            $table->dropColumn(['alert_timing_thresholds', 'feed_coverage_low_days']);
        });
    }
};
