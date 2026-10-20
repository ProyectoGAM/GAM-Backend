<?php

namespace Database\Seeders\Inventory;

use App\Actions\FarmStructure\CreatePoultryHouseAction;
use App\Actions\Inventory\ChangeFlockFeedingAction;
use App\Actions\Inventory\CorrectFeedConsumptionAction;
use App\Actions\Inventory\RecordDailyFeedConsumptionAction;
use App\Actions\Inventory\ReturnFlockFeedingToAutomaticAction;
use App\Actions\Lots\CreateFlockAction;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\FeedConsumption;
use App\Models\Lots\Breed;
use App\Models\Lots\Flock;
use App\Models\ManagementPlans\PlanTemplate;
use App\Models\User;
use App\Services\Inventory\FlockFeedingResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

final class FlockFeedingDemoSeeder extends Seeder
{
    public function run(CreateFlockAction $create, CreatePoultryHouseAction $houses, ChangeFlockFeedingAction $manual, ReturnFlockFeedingToAutomaticAction $automatic, RecordDailyFeedConsumptionAction $daily, CorrectFeedConsumptionAction $correct): void
    {
        if (! app()->environment('local') && ! (app()->environment('production') && config('app.demo_db_reset_enabled'))) {
            return;
        }
        $actor = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        $template = PlanTemplate::query()->where('name', 'Plan inicial de manejo (demo)')->firstOrFail();
        $unit = ProductionUnit::query()->orderBy('id')->firstOrFail();
        $house = PoultryHouse::query()->where('production_unit_id', $unit->id)->where('normalized_name', 'galpón alimentación demo')->first();
        $house ??= $houses->execute($unit, ['name' => 'Galpón Alimentación Demo', 'bird_capacity' => 500], $actor);
        $flock = Flock::query()->where('code', 'DEMO-FEEDING')->first();
        $originalTime = Carbon::getTestNow();
        $today = CarbonImmutable::now(FlockFeedingResolver::TIMEZONE)->startOfDay();
        try {
            if ($flock === null) {
                // La cronología ficticia registra cada alta en su propio día; nunca envía un ingreso retroactivo.
                Carbon::setTestNow($today->subDays(3)->setTime(12, 0));
                $operation = $create->execute([
                    'code' => 'DEMO-FEEDING', 'origin' => 'Cronología ficticia de alimentación',
                    'breed_id' => Breed::query()->where('status', 'active')->orderBy('id')->firstOrFail()->id,
                    'poultry_house_id' => $house->id, 'initial_quantity' => 100,
                    'entry_date' => now(FlockFeedingResolver::TIMEZONE)->toDateString(),
                    'plan_template_id' => $template->public_id, 'plan_template_version' => $template->published_version,
                    'idempotency_key' => 'fe000000-0000-4000-8000-000000000001',
                ], $actor, 'seeder');
                $flock = Flock::query()->where('public_id', $operation->result['flock']['public_id'])->firstOrFail();
            }
            $start = $flock->established_at->setTimezone(FlockFeedingResolver::TIMEZONE)->startOfDay();
            Carbon::setTestNow($start->addDay()->setTime(12, 0));
            $manual->execute($flock, ['cantidad_diaria_g' => '6500', 'idempotency_key' => 'fe000000-0000-4000-8000-000000000002'], $actor, 'seeder');
            Carbon::setTestNow($start->addDays(2)->setTime(12, 0));
            $automatic->execute($flock, 'fe000000-0000-4000-8000-000000000003', $actor, 'seeder');
            Carbon::setTestNow($originalTime);
            foreach ([0, 1, 2] as $offset) {
                $date = $start->addDays($offset)->toDateString();
                if ($date < $today->toDateString()) {
                    $daily->execute($flock->id, $date);
                }
            }
            $consumption = FeedConsumption::query()->where('flock_id', $flock->id)->where('consumption_date', $start->addDay()->toDateString())->first();
            if ($consumption !== null) {
                $correct->execute($consumption, ['cantidad_g' => '6000', 'motivo' => 'Recuento ficticio de alimento diario', 'idempotency_key' => 'fe000000-0000-4000-8000-000000000004'], $actor, 'seeder');
            }
        } finally {
            Carbon::setTestNow($originalTime);
        }
    }
}
