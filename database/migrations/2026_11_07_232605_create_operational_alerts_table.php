<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_alerts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('production_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 80);
            $table->string('source_key');
            $table->string('view_permission', 80);
            $table->string('entity_type', 80);
            $table->string('entity_id', 100);
            $table->string('target_path', 255)->nullable();
            $table->string('title', 180);
            $table->string('urgency', 10);
            $table->timestampTz('occurred_at');
            $table->timestampTz('resolved_at')->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX operational_alerts_open_identity_unique
            ON operational_alerts (COALESCE(production_unit_id, 0), type, source_key) WHERE resolved_at IS NULL');

        // Recupera sólo anomalías notificadas cuyo registro de origen sigue pendiente de revisión.
        DB::statement(<<<'SQL'
            INSERT INTO operational_alerts (
                public_id, production_unit_id, type, source_key, view_permission, entity_type,
                entity_id, target_path, title, urgency, occurred_at
            )
            SELECT DISTINCT ON (n.production_unit_id, n.entity_type, n.entity_id)
                n.public_id, n.production_unit_id, n.type, n.entity_type || ':' || n.entity_id,
                'can_view_weighings', n.entity_type, n.entity_id, n.target_path, n.title, n.urgency, n.occurred_at
            FROM app_notifications n
            WHERE n.type = 'weighing_anomaly' AND n.is_alert AND n.resolved_at IS NULL
              AND (
                (n.entity_type = 'weighing' AND EXISTS (
                    SELECT 1 FROM weighings w
                    WHERE w.public_id = n.entity_id AND w.production_unit_id = n.production_unit_id
                      AND w.outside_expected_range AND w.anomaly_reviewed_at IS NULL
                ))
                OR (n.entity_type = 'daily_weighing_entry' AND EXISTS (
                    SELECT 1 FROM daily_weighing_entries e
                    JOIN daily_weighings d ON d.id = e.daily_weighing_id
                    JOIN flocks f ON f.id = d.flock_id
                    WHERE e.public_id = n.entity_id AND f.production_unit_id = n.production_unit_id
                      AND e.outside_expected_range AND e.anomaly_reviewed_at IS NULL AND e.deleted_at IS NULL
                ))
              )
            ORDER BY n.production_unit_id, n.entity_type, n.entity_id, n.id DESC
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_alerts');
    }
};
