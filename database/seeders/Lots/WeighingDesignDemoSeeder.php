<?php

namespace Database\Seeders\Lots;

use App\Actions\FarmStructure\CreatePoultryHouseAction;
use App\Actions\FarmStructure\CreateProductionUnitAction;
use App\Actions\Lots\AddDailyWeighingEntryAction;
use App\Actions\Lots\CreateFlockAction;
use App\Actions\Lots\SaveBreedAction;
use App\Actions\Lots\SaveWeighingReferenceSettingsAction;
use App\Enums\FarmStructure\PoultryHouseStatus;
use App\Enums\FarmStructure\PoultryHouseType;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Lots\Breed;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\Lots\WeighingReferenceSettings;
use App\Models\ManagementPlans\PlanTemplate;
use App\Models\User;
use App\ValueObjects\Lots\FlockAge;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Database\Seeders\ManagementPlans\ManagementPlanDemoSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

final class WeighingDesignDemoSeeder extends Seeder
{
    private const FLOCK_CODE = 'LOT-PESAJE-TEST';

    public function run(
        CreateFlockAction $createFlock,
        AddDailyWeighingEntryAction $addEntry,
        CreatePoultryHouseAction $createHouse,
        CreateProductionUnitAction $createUnit,
        SaveBreedAction $saveBreed,
        SaveWeighingReferenceSettingsAction $saveSettings,
    ): void {
        if (! app()->environment('local')) {
            return;
        }

        $actor = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        $flock = Flock::query()->where('code', self::FLOCK_CODE)->first();
        if ($flock === null) {
            $house = $this->availableHouse($actor, $createHouse, $createUnit);
            $breed = Breed::query()->where('status', 'active')->orderBy('id')->first();
            if ($breed === null) {
                $operation = $saveBreed->execute(null, [
                    'name' => 'Ponedoras pesaje demo',
                    'idempotency_key' => 'e6500000-0000-4000-8000-000000100002',
                ], $actor, 'seeder');
                $breed = Breed::query()->findOrFail($operation->result['catalog']['id']);
            }
            $plan = PlanTemplate::query()->whereNotNull('published_version')->orderBy('id')->first();
            if ($plan === null) {
                $this->call(ManagementPlanDemoSeeder::class);
                $plan = PlanTemplate::query()->whereNotNull('published_version')->orderBy('id')->firstOrFail();
            }
            $entryDate = CarbonImmutable::now(config('lots.timezone'))->startOfDay()->subWeeks(59)->toDateString();
            $operation = $createFlock->execute([
                'code' => self::FLOCK_CODE,
                'breed_id' => $breed->id,
                'origin' => 'Datos de demostración para diseño de pesajes',
                'poultry_house_id' => $house->id,
                'initial_quantity' => 120,
                'entry_date' => $entryDate,
                'plan_template_id' => $plan->public_id,
                'plan_template_version' => $plan->published_version,
                'idempotency_key' => 'e6500000-0000-4000-8000-000000100001',
            ], $actor, 'seeder');
            $flock = Flock::query()->where('public_id', $operation->result['flock']['public_id'])->firstOrFail();
        }

        $settings = WeighingReferenceSettings::query()->first();
        if ($settings === null) {
            $saveSettings->execute([
                'adult_from_week' => 18,
                'unit' => 'g',
                'chick_min_weight' => '10.0',
                'chick_max_weight' => '100.0',
                'adult_min_weight' => '100.0',
                'adult_max_weight' => '3000.0',
                'idempotency_key' => 'e6500000-0000-4000-8000-000000100003',
            ], $actor, 'seeder');
            $settings = WeighingReferenceSettings::query()->firstOrFail();
        }

        $anchorDate = $flock->entry_date->addWeeks(59)->toDateString();
        $originalTestNow = Carbon::getTestNow();
        try {
            foreach ([28, 14, 7, 3, 1, 0] as $dayIndex => $daysAgo) {
                $localDate = CarbonImmutable::parse($anchorDate, config('lots.timezone'))->subDays($daysAgo);
                $week = FlockAge::on($flock->entry_date->toDateString(), $localDate, config('lots.timezone'))->week;
                $minimum = $week >= $settings->adult_from_week ? $settings->adult_min_weight_g : $settings->chick_min_weight_g;
                $maximum = $week >= $settings->adult_from_week ? $settings->adult_max_weight_g : $settings->chick_max_weight_g;

                for ($entryIndex = 0; $entryIndex < 12; $entryIndex++) {
                    $key = sprintf('e6500000-0000-4000-8000-%012d', 200000 + $dayIndex * 100 + $entryIndex);
                    if (FlockOperation::query()->where('created_by', $actor->id)->where('idempotency_key', $key)->exists()) {
                        continue;
                    }
                    $isGroup = $entryIndex >= 10;
                    $isAnomaly = ($dayIndex === 5 && in_array($entryIndex, [3, 11], true))
                        || ($dayIndex < 5 && $entryIndex === ($dayIndex % 2 === 0 ? 8 : 11));
                    $weight = $isAnomaly
                        ? $this->anomalousWeight($minimum, $maximum, $entryIndex % 2 === 0)
                        : $this->normalWeight($minimum, $maximum, $entryIndex);
                    $data = $isGroup
                        ? [
                            'mode' => 'group',
                            'bird_count' => $entryIndex === 10 ? 5 : 7,
                            'total_weight' => (string) BigDecimal::of($weight)->multipliedBy($entryIndex === 10 ? 5 : 7),
                        ]
                        : ['mode' => 'individual', 'weight' => $weight];
                    Carbon::setTestNow($localDate->setTime(10, 0)->addMinutes($entryIndex * 4));
                    $addEntry->execute($flock, [
                        ...$data,
                        'confirm_out_of_range' => $isAnomaly,
                        'idempotency_key' => $key,
                    ], $actor, 'seeder');
                }
            }
        } finally {
            Carbon::setTestNow($originalTestNow);
        }
    }

    private function availableHouse(User $actor, CreatePoultryHouseAction $createHouse, CreateProductionUnitAction $createUnit): PoultryHouse
    {
        $occupiedHouseIds = Flock::query()
            ->whereIn('status', ['active', 'quarantined'])
            ->select('poultry_house_id');
        $house = PoultryHouse::query()
            ->where('type', PoultryHouseType::Poultry)
            ->where('status', PoultryHouseStatus::Operational)
            ->where('bird_capacity', '>=', 120)
            ->whereHas('productionUnit', fn ($query) => $query->where('status', ProductionUnitStatus::Active))
            ->whereNotIn('id', $occupiedHouseIds)
            ->orderBy('id')
            ->first();
        if ($house !== null) {
            return $house;
        }

        $unit = ProductionUnit::query()->where('status', ProductionUnitStatus::Active)->orderBy('id')->first();
        $unit ??= $createUnit->execute([
            'locality_id' => null,
            'name' => 'UP Pesaje Test',
            'address' => 'Datos de demostración',
            'latitude' => '-34.900000',
            'longitude' => '-56.200000',
        ], $actor);

        return $createHouse->execute($unit, ['name' => 'Galpón Pesaje Test', 'bird_capacity' => 120], $actor);
    }

    private function normalWeight(string $minimum, string $maximum, int $index): string
    {
        $fraction = ['0.41', '0.45', '0.47', '0.49', '0.50', '0.52', '0.54', '0.56', '0.58', '0.60', '0.48', '0.53'][$index];

        return (string) BigDecimal::of($maximum)
            ->minus($minimum)
            ->multipliedBy($fraction)
            ->plus($minimum)
            ->toScale(1, RoundingMode::HalfUp);
    }

    private function anomalousWeight(string $minimum, string $maximum, bool $below): string
    {
        if ($below && BigDecimal::of($minimum)->isGreaterThan('0.1')) {
            return (string) BigDecimal::of($minimum)->dividedBy(2, 1, RoundingMode::HalfUp);
        }

        return (string) BigDecimal::of($maximum)->multipliedBy('1.1')->toScale(1, RoundingMode::HalfUp);
    }
}
