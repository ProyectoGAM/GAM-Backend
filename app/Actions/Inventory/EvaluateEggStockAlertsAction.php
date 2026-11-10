<?php

namespace App\Actions\Inventory;

use App\Actions\Notifications\ResolveAlertNotificationAction;
use App\Actions\Notifications\SynchronizeOperationalAlertsAction;
use App\Enums\Notifications\NotificationUrgency;
use App\Enums\Notifications\OperationalAlertType;
use App\Interfaces\Clock;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Notifications\AppNotification;
use App\Queries\Inventory\GetEggStockBalanceQuery;
use App\Services\Notifications\AlertConfiguration;
use Illuminate\Support\Facades\DB;

final readonly class EvaluateEggStockAlertsAction
{
    public function __construct(
        private SynchronizeOperationalAlertsAction $synchronize,
        private ResolveAlertNotificationAction $resolve,
        private GetEggStockBalanceQuery $balances,
        private Clock $clock,
        private AlertConfiguration $configuration,
    ) {}

    public function execute(int $unitId): void
    {
        DB::transaction(function () use ($unitId): void {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['egg-stock-alerts:'.$unitId]);
            $unit = ProductionUnit::query()->findOrFail($unitId);
            $balance = $this->balances->execute($unit)['balance'];
            $type = OperationalAlertType::EggStockNegative;
            $settings = $this->configuration->get($type, $unitId);
            $pending = $balance <= -(int) $settings['deficit_minimo_huevos'] ? [
                'egg-stock:'.$unitId => [
                    'entity_id' => (string) $unitId,
                    'target_path' => '/administracion/inventario/existencias/huevos',
                    'urgency' => NotificationUrgency::High,
                    'title' => 'Stock de huevos en negativo',
                    'message' => "El stock de huevos de «{$unit->name}» está en {$balance}.",
                ],
            ] : [];

            $this->synchronize->execute($unitId, $type, 'can_view_egg_stock', 'production_unit', $this->clock->now(), $pending, $settings);

            // Los avisos anteriores de carga se conservan en el historial de la bandeja.
            $legacy = AppNotification::query()->where('production_unit_id', $unitId)
                ->where('module', 'deliveries')->where('type', 'delivery_egg_stock_negative')
                ->where('is_alert', true)->whereNull('resolved_at');
            foreach ($legacy->lazyById(100) as $notice) {
                $this->resolve->execute($notice);
            }
        }, 3);
    }
}
