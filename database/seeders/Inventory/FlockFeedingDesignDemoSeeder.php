<?php

namespace Database\Seeders\Inventory;

use App\Actions\FarmStructure\CreatePoultryHouseAction;
use App\Actions\Inventory\CorrectFeedConsumptionAction;
use App\Actions\Inventory\RecordDailyFeedConsumptionAction;
use App\Actions\Inventory\ReturnFlockFeedingToAutomaticAction;
use App\Actions\Lots\CreateFlockAction;
use App\Actions\ManagementPlans\RecordManualPracticeAction;
use App\Actions\ManagementPlans\RecordRationChangeAction;
use App\Actions\ManagementPlans\RecordVaccinationApplicationAction;
use App\Actions\ManagementPlans\SavePlanTemplateAction;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Enums\Inventory\FeedRecipeStatus;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\FeedConsumption;
use App\Models\Inventory\FeedRecipe;
use App\Models\Lots\Breed;
use App\Models\Lots\Flock;
use App\Models\ManagementPlans\FlockPlan;
use App\Models\ManagementPlans\FlockPlanActivity;
use App\Models\ManagementPlans\PlanTemplate;
use App\Models\SuppliersAndCatalogs\Vaccine;
use App\Models\User;
use App\Services\Inventory\FlockFeedingResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class FlockFeedingDesignDemoSeeder extends Seeder
{
    private const FLOCK_CODE = 'LOT-FEED-TEST';

    public function run(
        CreateFlockAction $createFlock,
        CreatePoultryHouseAction $createHouse,
        SavePlanTemplateAction $templates,
        RecordManualPracticeAction $practices,
        RecordVaccinationApplicationAction $vaccinations,
        RecordRationChangeAction $rations,
        ReturnFlockFeedingToAutomaticAction $automatic,
        RecordDailyFeedConsumptionAction $daily,
        CorrectFeedConsumptionAction $correct,
    ): void {
        if (! app()->environment('local') && ! (app()->environment('production') && config('app.demo_db_reset_enabled'))) {
            return;
        }

        $originalTime = Carbon::getTestNow();
        try {
            DB::transaction(function () use ($createFlock, $createHouse, $templates, $practices, $vaccinations, $rations, $automatic, $daily, $correct): void {
                $actor = User::query()->where('email', config('auth.admin.email'))->lockForUpdate()->firstOrFail();
                // Conserva el fixture completo aunque luego el usuario modifique el lote o su plan.
                if (Flock::query()->where('code', self::FLOCK_CODE)->exists()) {
                    return;
                }

                $today = CarbonImmutable::now(FlockFeedingResolver::TIMEZONE)->startOfDay();
                $start = $today->subDays(7);
                $recipe = FeedRecipe::query()->where('status', FeedRecipeStatus::Active)->orderBy('id')->firstOrFail();
                $breed = Breed::query()->where('status', 'active')->orderBy('id')->firstOrFail();
                $vaccine = Vaccine::query()->whereHas('product', fn ($query) => $query->where('status', ProductStatus::Active))->orderBy('id')->firstOrFail();
                $unit = ProductionUnit::query()->where('status', ProductionUnitStatus::Active)->orderBy('id')->firstOrFail();

                // Simula las jornadas sin flexibilizar la validación del ingreso ordinario.
                Carbon::setTestNow($start->setTime(9, 0));
                $house = PoultryHouse::query()->where('production_unit_id', $unit->id)->where('normalized_name', 'galpón alimentación test')->first();
                $house ??= $createHouse->execute($unit, ['name' => 'Galpón Alimentación Test', 'bird_capacity' => 120], $actor);
                $operation = $templates->create([
                    'name' => 'Plan de manejo y alimentación de prueba',
                    'description' => 'Datos ficticios para LOT-FEED-TEST: etapas, prácticas, vacunación y consumo diario.',
                    'activities' => [
                        ['type' => 'ration_change', 'title' => 'Ración inicial', 'timing_kind' => 'day', 'start_day' => 1, 'catalog_ref' => $recipe->id, 'grams_per_bird' => 40],
                        ['type' => 'ration_change', 'title' => 'Ración de transición', 'timing_kind' => 'day', 'start_day' => 4, 'catalog_ref' => $recipe->id, 'grams_per_bird' => 60],
                        ['type' => 'ration_change', 'title' => 'Ración de crecimiento', 'timing_kind' => 'day', 'start_day' => 8, 'catalog_ref' => $recipe->id, 'grams_per_bird' => 80],
                        ['type' => 'manual_practice', 'title' => 'Revisión de comederos y bebederos', 'timing_kind' => 'day', 'start_day' => 1],
                        ['type' => 'manual_practice', 'title' => 'Control de adaptación a la ración', 'timing_kind' => 'day', 'start_day' => 4],
                        ['type' => 'vaccination', 'title' => 'Vacunación inicial de prueba', 'timing_kind' => 'day', 'start_day' => 2],
                    ],
                    'idempotency_key' => $this->key(1),
                ], $actor, 'seeder');
                $template = PlanTemplate::query()->where('public_id', $operation->result['template']['id'])->firstOrFail();
                $templates->publish($template, ['expected_version' => 1, 'idempotency_key' => $this->key(2)], $actor, 'seeder');
                $operation = $createFlock->execute([
                    'code' => self::FLOCK_CODE,
                    'origin' => 'Datos ficticios para probar manejo y alimentación',
                    'breed_id' => $breed->id,
                    'poultry_house_id' => $house->id,
                    'initial_quantity' => 120,
                    'entry_date' => $start->toDateString(),
                    'plan_template_id' => $template->public_id,
                    'plan_template_version' => 1,
                    'idempotency_key' => $this->key(3),
                ], $actor, 'seeder');
                $flock = Flock::query()->where('public_id', $operation->result['flock']['public_id'])->firstOrFail();
                $plan = FlockPlan::query()->where('flock_id', $flock->id)->firstOrFail();
                $revision = $plan->revisions()->where('number', $plan->current_revision)->firstOrFail();
                $activities = FlockPlanActivity::query()->where('flock_plan_revision_id', $revision->id)->get()->keyBy('title');

                foreach ([0 => 'Revisión de comederos y bebederos', 3 => 'Control de adaptación a la ración'] as $offset => $title) {
                    Carbon::setTestNow($start->addDays($offset)->setTime(10, 0));
                    $practices->execute($flock, [
                        'practice_type' => 'other', 'title' => $title,
                        'plan_activity_id' => $activities->get($title)->public_id,
                        'occurred_at' => now()->toIso8601String(),
                        'notes' => 'Control ficticio realizado sin incidencias.',
                        'idempotency_key' => $this->key(10 + $offset),
                    ], $actor, 'seeder');
                }
                Carbon::setTestNow($start->addDay()->setTime(10, 0));
                $vaccinations->execute($flock, [
                    'vaccine_id' => $vaccine->public_id,
                    'plan_activity_id' => $activities->get('Vacunación inicial de prueba')->public_id,
                    'occurred_at' => now()->toIso8601String(),
                    'notes' => 'Aplicación ficticia de prueba vinculada al plan.',
                    'idempotency_key' => $this->key(20),
                ], $actor, 'seeder');
                Carbon::setTestNow($start->addDays(4)->setTime(12, 0));
                $rations->execute($flock, [
                    'feed_recipe_id' => $recipe->id, 'daily_quantity_grams' => '8500',
                    'ration_description' => 'Ajuste manual de prueba durante la adaptación',
                    'plan_activity_id' => $activities->get('Ración de transición')->public_id,
                    'occurred_at' => now()->toIso8601String(),
                    'notes' => 'Total diario fijo para probar el modo manual.',
                    'idempotency_key' => $this->key(21),
                ], $actor, 'seeder');
                Carbon::setTestNow($start->addDays(6)->setTime(12, 0));
                $automatic->execute($flock, $this->key(22), $actor, 'seeder');

                Carbon::setTestNow($today->setTime(0, 0));
                foreach (range(0, 6) as $offset) {
                    $daily->execute($flock->id, $start->addDays($offset)->toDateString());
                }
                $consumption = FeedConsumption::query()->where('flock_id', $flock->id)->where('consumption_date', $start->addDays(5)->toDateString())->firstOrFail();
                $correct->execute($consumption, [
                    'cantidad_g' => '8200',
                    'motivo' => 'Recuento ficticio de la jornada: ajuste de 8500 a 8200 gramos.',
                    'idempotency_key' => $this->key(23),
                ], $actor, 'seeder');
            });
        } finally {
            Carbon::setTestNow($originalTime);
        }
    }

    private function key(int $operation): string
    {
        return sprintf('fe650000-0000-4000-8000-%012d', $operation);
    }
}
