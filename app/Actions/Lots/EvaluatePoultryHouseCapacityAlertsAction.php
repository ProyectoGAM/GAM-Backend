<?php

namespace App\Actions\Lots;

use App\Actions\Notifications\SynchronizeOperationalAlertsAction;
use App\Enums\FarmStructure\PoultryHouseType;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Enums\Lots\FlockStatus;
use App\Enums\Notifications\NotificationUrgency;
use App\Enums\Notifications\OperationalAlertType;
use App\Interfaces\Clock;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Lots\Flock;
use App\Models\Lots\WeighingReferenceSettings;
use App\Services\Notifications\AlertConfiguration;
use App\ValueObjects\Lots\FlockAge;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class EvaluatePoultryHouseCapacityAlertsAction
{
    public function __construct(
        private SynchronizeOperationalAlertsAction $synchronize,
        private Clock $clock,
        private AlertConfiguration $configuration,
    ) {}

    public function execute(int $unitId): void
    {
        DB::transaction(function () use ($unitId): void {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['poultry-house-capacity-alerts:'.$unitId]);
            $unit = ProductionUnit::query()->findOrFail($unitId);
            $evaluatedAt = $this->clock->now()->setTimezone((string) config('lots.timezone'));
            $settings = WeighingReferenceSettings::query()->first();
            $type = OperationalAlertType::PoultryHouseOverCapacity;
            $alert = $this->configuration->get($type, $unitId);
            $pending = $alert['habilitada'] && $unit->status === ProductionUnitStatus::Active && $settings !== null
                ? $this->pending($unitId, $settings->adult_from_week, $evaluatedAt, (int) $alert['dias_anticipacion'], (int) $alert['exceso_minimo_aves'])
                : [];

            $this->synchronize->execute($unitId, $type, 'can_view_poultry_houses', 'poultry_house', $evaluatedAt, $pending, $alert);
        }, 3);
    }

    /**
     * La restricción de un lote abierto por galpón permite leer su ocupación y edad en la misma consulta.
     *
     * @return array<string, array{entity_id: string, target_path: string, urgency: NotificationUrgency, title: string, message: string}>
     */
    private function pending(int $unitId, int $adultWeek, CarbonImmutable $evaluatedAt, int $noticeDays, int $minimumExcess): array
    {
        $flocks = Flock::query()->join('poultry_houses', 'poultry_houses.id', '=', 'flocks.poultry_house_id')
            ->where('flocks.production_unit_id', $unitId)
            ->where('poultry_houses.production_unit_id', $unitId)
            ->where('poultry_houses.type', PoultryHouseType::Poultry)
            ->whereIn('flocks.status', [FlockStatus::Active, FlockStatus::Quarantined])
            ->where('flocks.entry_date', '<=', $evaluatedAt->toDateString())
            ->whereRaw('flocks.current_quantity - poultry_houses.bird_capacity >= ?', [$minimumExcess])
            ->select('flocks.id', 'flocks.poultry_house_id', 'flocks.entry_date')
            ->selectRaw('poultry_houses.name as house_name, flocks.current_quantity - poultry_houses.bird_capacity as excess');
        $pending = [];

        foreach ($flocks->lazyById(100, 'flocks.id', 'id') as $flock) {
            $age = FlockAge::on($flock->entry_date->toDateString(), $evaluatedAt, $evaluatedAt->getTimezone()->getName());
            $daysUntilAdult = ($adultWeek - 1) * 7 - $age->days;
            if ($daysUntilAdult > $noticeDays) {
                continue;
            }

            $isAdult = $daysUntilAdult <= 0;
            $houseId = (string) $flock->poultry_house_id;
            $houseName = (string) $flock->getAttribute('house_name');
            $excess = (int) $flock->getAttribute('excess');
            $stage = match (true) {
                $isAdult => 'Las aves ya son adultas.',
                $daysUntilAdult === 1 => 'Falta un día para que las aves sean adultas.',
                default => "Faltan {$daysUntilAdult} días para que las aves sean adultas.",
            };
            $pending['poultry-house-capacity:'.$houseId] = [
                'entity_id' => $houseId,
                'target_path' => "/administracion/unidades-productivas/{$unitId}/galpon/{$houseId}",
                'urgency' => $isAdult ? NotificationUrgency::High : NotificationUrgency::Medium,
                'title' => 'Galpón por encima de su capacidad',
                'message' => "El galpón «{$houseName}» supera su capacidad máxima en {$excess} aves. {$stage}",
            ];
        }

        return $pending;
    }
}
