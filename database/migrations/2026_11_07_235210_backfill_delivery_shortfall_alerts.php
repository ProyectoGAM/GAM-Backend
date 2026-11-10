<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Recupera los avisos vigentes sólo cuando el saldo del reparto confirma el faltante.
        DB::statement(<<<'SQL'
            INSERT INTO operational_alerts (
                public_id, production_unit_id, type, source_key, view_permission, entity_type,
                entity_id, target_path, title, urgency, occurred_at
            )
            SELECT DISTINCT ON (d.id)
                n.public_id,
                CASE WHEN (d.return_production_unit_id IS NULL OR d.return_production_unit_id = d.production_unit_id)
                    AND NOT EXISTS (
                        SELECT 1 FROM delivery_loads l WHERE l.delivery_id = d.id AND l.production_unit_id <> d.production_unit_id
                    )
                    THEN d.production_unit_id END,
                n.type, d.public_id, 'can_view_delivery_history', 'delivery', d.public_id,
                n.target_path, n.title, n.urgency, n.occurred_at
            FROM app_notifications n
            JOIN deliveries d ON d.public_id = n.entity_id
            WHERE n.type = 'delivery_egg_balance_negative' AND n.entity_type = 'delivery'
              AND n.is_alert AND n.resolved_at IS NULL AND d.status IN ('active', 'completed')
              AND d.loaded_quantity - d.returned_quantity < (
                SELECT COALESCE(SUM(s.delivered_quantity), 0) FROM delivery_stops s
                WHERE s.delivery_id = d.id AND s.status = 'delivered'
              )
            ORDER BY d.id, n.id DESC
            ON CONFLICT DO NOTHING
            SQL);
    }

    public function down(): void
    {
        // Los episodios recuperados reutilizan el ULID de uno de sus avisos originales.
        DB::table('operational_alerts')->where('type', 'delivery_egg_balance_negative')
            ->whereIn('public_id', DB::table('app_notifications')->where('type', 'delivery_egg_balance_negative')->select('public_id'))
            ->delete();
    }
};
