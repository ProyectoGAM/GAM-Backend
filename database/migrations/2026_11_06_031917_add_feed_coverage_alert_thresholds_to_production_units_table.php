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
            $table->smallInteger('feed_coverage_warning_days')->default(7);
            $table->smallInteger('feed_coverage_urgent_days')->default(2);
        });

        DB::statement('ALTER TABLE production_units ADD CONSTRAINT production_units_feed_coverage_thresholds_check CHECK (feed_coverage_urgent_days >= 0 AND feed_coverage_urgent_days < feed_coverage_warning_days AND feed_coverage_warning_days <= 365)');
        DB::statement("CREATE UNIQUE INDEX app_notifications_open_feed_coverage_unique ON app_notifications (production_unit_id, entity_id, recipient_id) WHERE type = 'feed_coverage' AND module = 'management_plans' AND is_alert AND resolved_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX app_notifications_open_feed_coverage_unique');

        Schema::table('production_units', function (Blueprint $table): void {
            $table->dropColumn(['feed_coverage_warning_days', 'feed_coverage_urgent_days']);
        });
    }
};
