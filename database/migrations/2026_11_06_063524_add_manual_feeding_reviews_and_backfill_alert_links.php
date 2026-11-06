<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flock_feeding_changes', function (Blueprint $table): void {
            $table->date('manual_review_since')->nullable();
            $table->timestampTz('manual_confirmed_at')->nullable();
            $table->foreignId('manual_confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            WITH ordered AS (
                SELECT id, flock_id, mode, effective_date,
                    CASE WHEN mode = lag(mode) OVER sequence
                        AND feed_recipe_id = lag(feed_recipe_id) OVER sequence
                        AND daily_quantity_grams = lag(daily_quantity_grams) OVER sequence
                        THEN 0 ELSE 1 END AS starts_new_period
                FROM flock_feeding_changes
                WINDOW sequence AS (PARTITION BY flock_id ORDER BY effective_date, id)
            ), periods AS (
                SELECT *, sum(starts_new_period) OVER (PARTITION BY flock_id ORDER BY effective_date, id) AS period
                FROM ordered
            ), anchors AS (
                SELECT id, min(effective_date) OVER (PARTITION BY flock_id, period) AS since
                FROM periods WHERE mode = 'manual'
            )
            UPDATE flock_feeding_changes AS changes SET manual_review_since = anchors.since
            FROM anchors WHERE changes.id = anchors.id
            SQL);

        DB::statement(<<<'SQL'
            UPDATE app_notifications AS notice
            SET target_path = '/administracion/manejo-lotes/planes/lotes/' || flock.public_id
            FROM flock_plan_activities AS activity
            JOIN flock_plan_revisions AS revision ON revision.id = activity.flock_plan_revision_id
            JOIN flock_plans AS plan ON plan.id = revision.flock_plan_id
            JOIN flocks AS flock ON flock.id = plan.flock_id
            WHERE notice.entity_id = activity.public_id AND notice.entity_type = 'flock_plan_activity'
                AND notice.is_alert AND notice.module = 'management_plans' AND notice.target_path IS NULL
            SQL);
        DB::table('app_notifications')->where('is_alert', true)->where('type', 'feed_coverage')
            ->where('entity_type', 'feed_recipe')->whereNull('target_path')
            ->update(['target_path' => '/administracion/inventario/existencias/raciones']);
    }

    public function down(): void
    {
        Schema::table('flock_feeding_changes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('manual_confirmed_by');
            $table->dropColumn(['manual_review_since', 'manual_confirmed_at']);
        });
    }
};
