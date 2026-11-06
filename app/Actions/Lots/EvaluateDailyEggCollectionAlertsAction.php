<?php

namespace App\Actions\Lots;

use App\Actions\Notifications\SynchronizeOperationalAlertsAction;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Enums\Lots\FlockStatus;
use App\Enums\Notifications\AlertTimingType;
use App\Enums\Notifications\NotificationUrgency;
use App\Interfaces\Clock;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Lots\EggCollection;
use App\Models\Lots\Flock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class EvaluateDailyEggCollectionAlertsAction
{
    public function __construct(private SynchronizeOperationalAlertsAction $synchronize, private Clock $clock) {}

    public function execute(int $unitId): void
    {
        DB::transaction(function () use ($unitId): void {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['daily-egg-collection-alerts:'.$unitId]);
            $unit = ProductionUnit::query()->findOrFail($unitId);
            $evaluatedAt = $this->clock->now()->setTimezone((string) config('lots.timezone'));
            $pending = $unit->status === ProductionUnitStatus::Active ? $this->pending($unit, $evaluatedAt) : [];

            $this->synchronize->execute($unitId, 'lots', AlertTimingType::DailyEggCollectionMissing->value, 'can_view_egg_collections', 'flock', $evaluatedAt, $pending);
        }, 3);
    }

    /** @return array<string, array{entity_id: string, target_path: string, urgency: NotificationUrgency, title: string, message: string}> */
    private function pending(ProductionUnit $unit, CarbonImmutable $evaluatedAt): array
    {
        $today = $evaluatedAt->startOfDay();
        $thresholds = AlertTimingType::DailyEggCollectionMissing->thresholds($unit);
        $latest = EggCollection::query()->select('occurred_at')->whereColumn('flock_id', 'flocks.id')
            ->where('status', 'recorded')->where('occurred_at', '<=', $evaluatedAt->toIso8601String())
            ->orderByDesc('occurred_at')->limit(1);
        $flocks = Flock::query()->where('production_unit_id', $unit->id)
            ->whereIn('status', [FlockStatus::Active, FlockStatus::Quarantined])
            ->where('current_quantity', '>', 0)->where('established_at', '<=', $evaluatedAt->toIso8601String())
            ->addSelect(['last_collection_at' => $latest]);
        $pending = [];

        foreach ($flocks->lazyById(100) as $flock) {
            $firstMissing = $flock->established_at->setTimezone($today->getTimezone())->startOfDay();
            $latestAt = $flock->getAttribute('last_collection_at');
            if ($latestAt !== null) {
                $afterLatest = CarbonImmutable::parse($latestAt)->setTimezone($today->getTimezone())->startOfDay()->addDay();
                $firstMissing = $firstMissing->max($afterLatest);
            }

            $days = (int) $firstMissing->diffInDays($today, false);
            $urgency = $thresholds->urgency($days);
            if ($urgency === null) {
                continue;
            }

            $duration = $days === 1 ? '1 día completo' : "{$days} días completos";
            $pending['daily-egg-collection:'.$flock->public_id.':'.$firstMissing->toDateString()] = [
                'entity_id' => $flock->public_id,
                'target_path' => '/administracion/lotes/lotes/'.$flock->public_id.'/recoleccion',
                'urgency' => $urgency,
                'title' => 'Recolección diaria de huevos pendiente',
                'message' => "El lote «{$flock->code}» lleva {$duration} sin registrar una recolección de huevos.",
            ];
        }

        return $pending;
    }
}
