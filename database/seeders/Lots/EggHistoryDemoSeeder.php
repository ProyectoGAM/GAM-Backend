<?php

namespace Database\Seeders\Lots;

use App\Actions\FarmStructure\CreatePoultryHouseAction;
use App\Actions\Lots\AddDailyWeighingEntryAction;
use App\Actions\Lots\CreateFlockAction;
use App\Actions\Lots\RecordEggCollectionAction;
use App\Actions\Lots\RecordMortalityAction;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Lots\Breed;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\ManagementPlans\PlanTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EggHistoryDemoSeeder extends Seeder
{
    private const FLOCK_CODE = 'LOT-EGG-TEST';

    public function run(
        CreateFlockAction $createFlock,
        CreatePoultryHouseAction $createHouse,
        RecordMortalityAction $mortality,
        RecordEggCollectionAction $collections,
        AddDailyWeighingEntryAction $weighings,
    ): void {
        if (! app()->environment('local')) {
            return;
        }

        $originalTestNow = Carbon::getTestNow();
        try {
            DB::transaction(function () use ($createFlock, $createHouse, $mortality, $collections, $weighings): void {
                $actor = User::query()->where('email', config('auth.admin.email'))->lockForUpdate()->firstOrFail();
                $flock = Flock::query()->where('code', self::FLOCK_CODE)->first();
                if ($flock === null) {
                    $unit = ProductionUnit::query()->where('status', ProductionUnitStatus::Active)->orderBy('id')->firstOrFail();
                    $house = PoultryHouse::query()->where('production_unit_id', $unit->id)->where('normalized_name', 'galpón huevos test')->first();
                    $house ??= $createHouse->execute($unit, ['name' => 'Galpón Huevos Test', 'bird_capacity' => 240], $actor);
                    $breed = Breed::query()->where('status', 'active')->orderBy('id')->firstOrFail();
                    $plan = PlanTemplate::query()->whereNotNull('published_version')->orderBy('id')->firstOrFail();
                    $operation = $createFlock->execute([
                        'code' => self::FLOCK_CODE,
                        'breed_id' => $breed->id,
                        'origin' => 'Datos de demostración de producción de huevos',
                        'poultry_house_id' => $house->id,
                        'initial_quantity' => 240,
                        'entry_date' => CarbonImmutable::now(config('lots.timezone'))->startOfDay()->subWeeks(29)->toDateString(),
                        'plan_template_id' => $plan->public_id,
                        'plan_template_version' => $plan->published_version,
                        'idempotency_key' => $this->key(1),
                    ], $actor, 'seeder');
                    $flock = Flock::query()->where('public_id', $operation->result['flock']['public_id'])->firstOrFail();
                }

                /** Las fechas quedan ancladas al ingreso para conservar el fixture al repetirlo otro día. */
                $lastDate = CarbonImmutable::parse($flock->entry_date->toDateString(), config('lots.timezone'))->addWeeks(29)->subDay();
                $weeklyDeaths = [2, 1, 1, 2, 1, 1, 2, 1];
                for ($day = 0; $day < 56; $day++) {
                    $date = $lastDate->subDays(55 - $day);
                    $week = intdiv($day, 7);
                    if ($day % 7 === 0) {
                        Carbon::setTestNow($date->setTime(6, 0));
                        $this->once($actor, 100 + $week, fn (string $key): FlockOperation => $mortality->execute($flock, [
                            'version' => $flock->fresh()->version,
                            'quantity' => $weeklyDeaths[$week],
                            'occurred_at' => $date->setTime(6, 0)->toIso8601String(),
                            'notes' => 'Bajas registradas durante la revisión sanitaria semanal de demostración.',
                            'idempotency_key' => $key,
                        ], $actor, 'seeder'));
                    }

                    $total = 140 + $week * 6 + $day % 11;
                    $morning = intdiv($total, 2);
                    $afternoon = intdiv($total * 35, 100);
                    $quantities = [8 => $morning, 13 => $afternoon, 17 => $total - $morning - $afternoon];
                    foreach ([8, 10, 13, 17] as $hour) {
                        if ($hour === 10) {
                            if ($day % 7 === 6) {
                                $this->seedWeighing($actor, $flock, $date, $week, $weighings);
                            }

                            continue;
                        }

                        Carbon::setTestNow($date->setTime($hour, 0));
                        $this->once($actor, 1000 + $day * 100 + $hour, fn (string $key): FlockOperation => $collections->execute($flock, [
                            'quantity' => $quantities[$hour],
                            'occurred_at' => $date->setTime($hour, 0)->toIso8601String(),
                            'notes' => sprintf('Recolección demo de las %02d:00, semana %d de historial.', $hour, $week + 1),
                            'idempotency_key' => $key,
                        ], $actor, 'seeder'));
                    }
                }
            });
        } finally {
            Carbon::setTestNow($originalTestNow);
        }
    }

    private function seedWeighing(User $actor, Flock $flock, CarbonImmutable $date, int $week, AddDailyWeighingEntryAction $weighings): void
    {
        for ($entry = 0; $entry < 12; $entry++) {
            Carbon::setTestNow($date->setTime(10, 0)->addMinutes($entry * 3));
            $weight = 1750 + $week * 20 + ($entry - 5) * 15;
            $data = $entry < 10
                ? ['mode' => 'individual', 'weight' => (string) $weight]
                : ['mode' => 'group', 'bird_count' => 5, 'total_weight' => (string) ($weight * 5)];
            $this->once($actor, 10000 + $week * 100 + $entry, fn (string $key): FlockOperation => $weighings->execute($flock, [
                ...$data,
                'confirm_out_of_range' => true,
                'idempotency_key' => $key,
            ], $actor, 'seeder'));
        }
    }

    /** @param Closure(string): FlockOperation $create */
    private function once(User $actor, int $number, Closure $create): FlockOperation
    {
        $key = $this->key($number);

        return FlockOperation::query()->where('created_by', $actor->id)->where('idempotency_key', $key)->first() ?? $create($key);
    }

    private function key(int $number): string
    {
        return sprintf('e6600000-0000-4000-8000-%012d', $number);
    }
}
