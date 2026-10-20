<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\ChangeFeedRecipeStatusAction;
use App\Actions\Inventory\ChangeFlockFeedingAction;
use App\Actions\Inventory\CorrectFeedConsumptionAction;
use App\Actions\Inventory\RecordDailyFeedConsumptionAction;
use App\Enums\Inventory\FeedRecipeStatus;
use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\FeedBalance;
use App\Models\Inventory\FeedConsumption;
use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\FlockFeedingChange;
use App\Models\Lots\Breed;
use App\Models\Lots\Flock;
use App\Models\ManagementPlans\PlanTemplate;
use App\Models\SharedDevice;
use App\Models\User;
use App\Services\IdentityAndAccess\AuthSessionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Lots\LotsTestCase;

final class FlockFeedingEndpointTest extends LotsTestCase
{
    /** @param list<array<string,mixed>> $stages */
    private function admitted(int $quantity = 100, array $stages = []): Flock
    {
        $actor = User::query()->findOrFail(auth()->id());
        $activities = [];
        foreach ($stages as $index => $stage) {
            $recipe = $stage['recipe'] ?? FeedRecipe::factory()->create();
            $activities[] = [
                'type' => 'ration_change', 'title' => 'Alimentación '.($index + 1),
                'timing_kind' => 'day', 'start_day' => $stage['day'],
                'start_week' => null, 'feed_recipe_id' => $recipe->id, 'grams_per_bird' => $stage['grams'],
                'catalog_type' => 'feed_recipe', 'catalog_id' => $recipe->id,
                'catalog_snapshot' => ['id' => $recipe->id, 'name' => $recipe->name],
            ];
        }
        $plan = $this->createPublishedPlanSelection($actor, $activities);
        $house = PoultryHouse::factory()->create(['bird_capacity' => max(100, $quantity)]);
        $id = $this->command('POST', '/flocks', [
            'code' => 'FEED-'.Str::upper(Str::random(8)), 'breed_id' => Breed::factory()->create()->id,
            'origin' => 'Cría de prueba', 'poultry_house_id' => $house->id, 'initial_quantity' => $quantity,
            'entry_date' => now('America/Montevideo')->toDateString(), ...$plan,
        ])->assertCreated()->json('data.flock.id');

        return Flock::query()->where('public_id', $id)->firstOrFail();
    }

    private function localTime(string $date): void
    {
        $this->travelTo(CarbonImmutable::parse($date, 'America/Montevideo'));
    }

    private function feedingUrl(Flock $flock): string
    {
        return '/lotes/'.$flock->public_id.'/alimentacion';
    }

    private function consume(Flock $flock, string $date): FeedConsumption
    {
        return $this->app->make(RecordDailyFeedConsumptionAction::class)->execute($flock->id, $date);
    }

    // Flujo: inicia automático y calcula la jornada de creación con mortalidad al cierre.
    public function test_daily_consumption_uses_survivors_and_records_exact_negative_feed_balance(): void
    {
        // Preparación: crea mil aves el lunes al mediodía.
        $this->localTime('2026-10-05 12:00');
        $this->signIn();
        $flock = $this->admitted(1000);
        $this->getJson('/api/v1'.$this->feedingUrl($flock))->assertOk()->assertJsonPath('data.modo', 'automatico')->assertJsonPath('data.cantidad_diaria_g', '40000');

        // Request: registra cien bajas del lunes.
        $this->command('POST', '/flocks/'.$flock->public_id.'/mortalities', [
            'version' => 1, 'quantity' => 100, 'notes' => 'Bajas del día', 'occurred_at' => now()->toIso8601String(),
        ])->assertCreated();

        // Mutación: el martes a medianoche registra y reintenta el lunes.
        $this->localTime('2026-10-06 00:00');
        $first = $this->consume($flock, '2026-10-05');
        $replay = $this->consume($flock, '2026-10-05');
        $this->assertSame(900, $first->population);
        $this->assertSame(36000, $first->quantity_grams);
        $this->assertSame($first->id, $replay->id);
        $this->assertDatabaseCount('feed_consumptions', 1);
        $this->assertDatabaseCount('feed_consumption_movements', 1);
        $this->assertDatabaseHas('feed_balances', ['production_unit_id' => $flock->production_unit_id, 'quantity_grams' => -36000]);
        $this->assertDatabaseHas('activity_log', ['event' => 'feed_consumption_recorded', 'causer_id' => null, 'source' => 'scheduler', 'up_id' => $flock->production_unit_id]);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('feed_stock_operations', 0);
    }

    /** @return array<string,array{int,int}> */
    public static function exhaustedPopulations(): array
    {
        return ['cero' => [100, 0], 'negativa' => [110, -10]];
    }

    // Flujo: conserva el registro automático sin movimiento cuando el censo no es positivo.
    #[DataProvider('exhaustedPopulations')]
    public function test_nonpositive_population_has_zero_consumption_without_stock_movement(int $deaths, int $population): void
    {
        // Preparación: admite cien aves y registra todas las bajas declaradas.
        $this->localTime('2026-10-05 12:00');
        $this->signIn();
        $flock = $this->admitted();
        $this->command('POST', '/flocks/'.$flock->public_id.'/mortalities', [
            'version' => 1, 'quantity' => $deaths, 'notes' => 'Recuento de bajas', 'occurred_at' => now()->toIso8601String(),
        ])->assertCreated();

        // Mutación: registra la jornada cerrada y conserva el censo real.
        $this->localTime('2026-10-06 00:00');
        $consumption = $this->consume($flock, '2026-10-05');
        $this->assertSame($population, $consumption->population);
        $this->assertSame(0, $consumption->quantity_grams);
        $this->assertDatabaseCount('feed_consumption_movements', 0);
        $this->assertDatabaseCount('feed_balances', 0);
    }

    // Flujo: las etapas avanzan y una receta futura inactiva conserva receta y gramos anteriores.
    public function test_unavailable_stages_are_skipped_using_historical_recipe_availability(): void
    {
        // Preparación: asigna cuatro etapas diarias con recetas distintas.
        $this->localTime('2026-10-05 12:00');
        $actor = $this->signIn(['flocks.manage', 'feed-recipes.manage']);
        $recipes = FeedRecipe::factory()->count(4)->create();
        $flock = $this->admitted(stages: [
            ['day' => 1, 'grams' => 40, 'recipe' => $recipes[0]],
            ['day' => 2, 'grams' => 60, 'recipe' => $recipes[1]],
            ['day' => 3, 'grams' => 80, 'recipe' => $recipes[2]],
            ['day' => 4, 'grams' => 100, 'recipe' => $recipes[3]],
        ]);
        app(ChangeFeedRecipeStatusAction::class)->execute($recipes[2], FeedRecipeStatus::Inactive, $actor);

        // Mutación: desactiva la receta vigente después de que su etapa comenzó.
        $this->localTime('2026-10-07 12:00');
        app(ChangeFeedRecipeStatusAction::class)->execute($recipes[1], FeedRecipeStatus::Inactive, $actor);
        $this->localTime('2026-10-09 00:00');
        $monday = $this->consume($flock, '2026-10-05');
        $tuesday = $this->consume($flock, '2026-10-06');
        $wednesday = $this->consume($flock, '2026-10-07');
        $thursday = $this->consume($flock, '2026-10-08');
        $this->assertSame($recipes[0]->id, $monday->feed_recipe_id);
        $this->assertSame($recipes[1]->id, $tuesday->feed_recipe_id);
        $this->assertSame(6000, $wednesday->quantity_grams);
        $this->assertSame($recipes[1]->id, $wednesday->feed_recipe_id);
        $this->assertSame($recipes[3]->id, $thursday->feed_recipe_id);
        $this->assertSame(10000, $thursday->quantity_grams);
    }

    // Flujo: el cambio manual del miércoles no altera lunes y martes pendientes y sigue fijo con cero aves.
    public function test_manual_configuration_is_effective_today_and_does_not_rewrite_pending_days(): void
    {
        // Preparación: crea el lote el lunes.
        $this->localTime('2026-10-05 12:00');
        $this->signIn();
        $flock = $this->admitted(stages: [['day' => 1, 'grams' => 40], ['day' => 4, 'grams' => 90]]);

        // Request: configura un total manual y agota la población el miércoles.
        $this->localTime('2026-10-07 12:00');
        $manual = $this->command('POST', $this->feedingUrl($flock).'/manual', ['cantidad_diaria_g' => '12345'])->assertCreated();
        $this->command('POST', '/flocks/'.$flock->public_id.'/mortalities', [
            'version' => 1, 'quantity' => 100, 'notes' => 'Bajas totales', 'occurred_at' => now()->toIso8601String(),
        ])->assertCreated();

        // Mutación: recupera todas las jornadas y conserva la configuración fija.
        $this->localTime('2026-10-10 00:00');
        $this->artisan('alimentacion:consumir')->assertSuccessful();
        $this->assertDatabaseHas('feed_consumptions', ['consumption_date' => '2026-10-05', 'quantity_grams' => 4000, 'mode' => 'automatic']);
        $this->assertDatabaseHas('feed_consumptions', ['consumption_date' => '2026-10-06', 'quantity_grams' => 4000]);
        $this->assertDatabaseHas('feed_consumptions', ['consumption_date' => '2026-10-07', 'quantity_grams' => 12345, 'mode' => 'manual', 'population' => 0]);
        $this->assertDatabaseHas('feed_consumptions', ['consumption_date' => '2026-10-09', 'quantity_grams' => 12345]);
        $this->getJson('/api/v1'.$this->feedingUrl($flock))->assertOk()->assertJsonPath('data.cantidad_manual_g', '12345');
        $this->artisan('alimentacion:consumir')->assertSuccessful();
        $this->assertDatabaseCount('feed_consumptions', 5);
        $this->assertDatabaseCount('feed_consumption_movements', 5);
    }

    // Flujo: cambiar sólo receta fija el total vigente y cambiar sólo cantidad conserva una receta desactivada.
    public function test_partial_manual_changes_freeze_both_values_and_require_active_new_recipe(): void
    {
        // Preparación: crea un lote y una receta seleccionable.
        $this->localTime('2026-10-05 12:00');
        $actor = $this->signIn(['flocks.manage', 'mortality.manage', 'feed-recipes.manage']);
        $flock = $this->admitted();
        $recipe = FeedRecipe::factory()->create();
        $key = (string) Str::uuid();

        // Request: sólo la receta conserva el total automático del momento y el reintento.
        $first = $this->command('POST', $this->feedingUrl($flock).'/manual', ['receta_id' => $recipe->id], $key)->assertCreated();
        $this->command('POST', $this->feedingUrl($flock).'/manual', ['receta_id' => $recipe->id], $key)->assertCreated()->assertExactJson($first->json());
        app(ChangeFeedRecipeStatusAction::class)->execute($recipe, FeedRecipeStatus::Inactive, $actor);

        // Request: cambiar cantidad no exige reactivar la receta vigente.
        $this->command('POST', $this->feedingUrl($flock).'/manual', ['cantidad_diaria_g' => '5000'])->assertCreated();
        $this->getJson('/api/v1'.$this->feedingUrl($flock))->assertOk()->assertJsonPath('data.receta_id', $recipe->id)->assertJsonPath('data.cantidad_diaria_g', '5000');
        $this->command('POST', $this->feedingUrl($flock).'/manual', ['receta_id' => $recipe->id])->assertConflict();
        $this->command('POST', $this->feedingUrl($flock).'/manual', ['cantidad_diaria_g' => '0'])->assertUnprocessable();
        $this->command('POST', $this->feedingUrl($flock).'/manual', ['cantidad_diaria_g' => '1.5'])->assertUnprocessable();
        $this->command('POST', $this->feedingUrl($flock).'/manual', ['cantidad_diaria_g' => '6000'], $key)->assertConflict();
        $this->assertDatabaseCount('flock_feeding_changes', 3);
    }

    // Flujo: volver a automático retoma la etapa de la edad actual y vuelve a considerar mortalidad.
    public function test_return_to_automatic_applies_current_plan_stage_and_preserves_manual_history(): void
    {
        // Preparación: configura un total fijo antes de la segunda etapa.
        $this->localTime('2026-10-05 12:00');
        $this->signIn();
        $recipe = FeedRecipe::factory()->create();
        $flock = $this->admitted(stages: [['day' => 1, 'grams' => 40], ['day' => 3, 'grams' => 80, 'recipe' => $recipe]]);
        $this->command('POST', $this->feedingUrl($flock).'/manual', ['cantidad_diaria_g' => '5000'])->assertCreated();

        // Request: abandona manual y registra bajas del día actual.
        $this->localTime('2026-10-07 12:00');
        $this->command('POST', $this->feedingUrl($flock).'/automatico', [])->assertCreated();
        $this->command('POST', '/flocks/'.$flock->public_id.'/mortalities', [
            'version' => 1, 'quantity' => 10, 'notes' => 'Bajas', 'occurred_at' => now()->toIso8601String(),
        ])->assertCreated();

        // Mutación: la recuperación conserva días manuales y recalcula desde el regreso.
        $this->localTime('2026-10-08 00:00');
        $this->assertSame(5000, $this->consume($flock, '2026-10-05')->quantity_grams);
        $current = $this->consume($flock, '2026-10-07');
        $this->assertSame(7200, $current->quantity_grams);
        $this->assertSame($recipe->id, $current->feed_recipe_id);
        $this->assertDatabaseHas('activity_log', ['event' => 'flock_feeding_automatic_restored']);
    }

    // Flujo: la finalización conserva la última jornada completa y la cuarentena no interrumpe consumos.
    #[DataProvider('feedingModes')]
    public function test_recovery_includes_creation_and_finalization_days_and_quarantine(bool $manual): void
    {
        // Preparación: crea el lote el lunes.
        $this->localTime('2026-10-05 18:00');
        $this->signIn();
        $flock = $this->admitted();
        if ($manual) {
            $this->command('POST', $this->feedingUrl($flock).'/manual', ['cantidad_diaria_g' => '5000'])->assertCreated();
        }

        // Request: pone en cuarentena y finaliza el miércoles a media tarde.
        $this->localTime('2026-10-06 12:00');
        $this->command('PATCH', '/flocks/'.$flock->public_id.'/status', ['version' => 1, 'status' => 'quarantined', 'reason' => 'Observación'])->assertOk();
        $this->localTime('2026-10-07 16:00');
        $this->command('POST', '/flocks/'.$flock->public_id.'/finalization', ['version' => 2, 'reason' => 'Fin del ciclo'])->assertOk();

        // Mutación: recupera el viernes sin eliminar el alimento de la jornada final.
        $this->localTime('2026-10-09 00:00');
        $this->artisan('alimentacion:consumir')->assertSuccessful();
        $this->assertDatabaseCount('feed_consumptions', 3);
        $this->assertDatabaseHas('feed_consumptions', ['consumption_date' => '2026-10-07', 'population' => 100, 'quantity_grams' => $manual ? 5000 : 4000]);
        $this->assertDatabaseHas('feed_balances', ['quantity_grams' => $manual ? -15000 : -12000]);
        $this->command('POST', $this->feedingUrl($flock).'/manual', ['cantidad_diaria_g' => '1000'])->assertConflict();
    }

    /** @return array<string,array{bool}> */
    public static function feedingModes(): array
    {
        return ['automático' => [false], 'manual' => [true]];
    }

    // Flujo: el fraccionamiento copia edad y etapas o conserva exactamente el total manual en ambos lotes.
    #[DataProvider('feedingModes')]
    public function test_split_preserves_age_and_feeding_mode_without_scaling_manual_total(bool $manual): void
    {
        // Preparación: crea un lote con una etapa a partir de la semana diez.
        $this->localTime('2026-08-03 12:00');
        $this->signIn();
        $recipe = FeedRecipe::factory()->create();
        $flock = $this->admitted(stages: [['day' => 1, 'grams' => 40], ['day' => 64, 'grams' => 80, 'recipe' => $recipe]]);
        $this->localTime('2026-10-06 12:00');
        if ($manual) {
            $this->command('POST', $this->feedingUrl($flock).'/manual', ['receta_id' => $recipe->id, 'cantidad_diaria_g' => '12345'])->assertCreated();
        }
        $house = PoultryHouse::factory()->create(['production_unit_id' => $flock->production_unit_id]);

        // Request: fracciona cuarenta aves a un lote nuevo en la misma UP.
        $split = $this->command('POST', '/flocks/'.$flock->public_id.'/redistributions', [
            'version' => 1, 'quantity' => 40, 'destination_code' => 'FEED-SPLIT', 'destination_poultry_house_id' => $house->id,
        ])->assertCreated();
        $destination = Flock::query()->where('public_id', $split->json('data.destination_flock.id'))->firstOrFail();

        // Mutación: ambos consumen según su modo en el día del fraccionamiento.
        $this->localTime('2026-10-07 00:00');
        $sourceConsumption = $this->consume($flock, '2026-10-06');
        $destinationConsumption = $this->consume($destination, '2026-10-06');
        $this->assertSame($manual ? 12345 : 4800, $sourceConsumption->quantity_grams);
        $this->assertSame($manual ? 12345 : 3200, $destinationConsumption->quantity_grams);
        $this->assertSame($recipe->id, $destinationConsumption->feed_recipe_id);
        $this->assertSame(65, $destinationConsumption->age_days);
    }

    // Flujo: incorporar aves conserva plan y alimentación del receptor.
    #[DataProvider('feedingModes')]
    public function test_existing_receiver_keeps_plan_recipe_and_manual_total(bool $manual): void
    {
        // Preparación: configura un receptor distinto en la misma UP.
        $this->localTime('2026-10-05 12:00');
        $actor = $this->signIn();
        $source = $this->admitted();
        $house = PoultryHouse::factory()->create(['production_unit_id' => $source->production_unit_id]);
        $receiver = $this->flockWithPlan(20, Breed::query()->findOrFail($source->breed_id), $house);
        $configuration = FlockFeedingChange::query()->where('flock_id', $receiver->id)->firstOrFail();
        if ($manual) {
            $this->command('POST', $this->feedingUrl($receiver).'/manual', ['cantidad_diaria_g' => '7777'])->assertCreated();
        }

        // Request: incorpora treinta aves al receptor existente.
        $this->command('POST', '/flocks/'.$source->public_id.'/redistributions', [
            'version' => 1, 'quantity' => 30, 'destination_flock_id' => $receiver->public_id, 'destination_version' => 1,
        ])->assertCreated();

        // Consulta: el receptor conserva su receta y el total según su modo.
        $this->getJson('/api/v1'.$this->feedingUrl($receiver))->assertOk()
            ->assertJsonPath('data.receta_id', $configuration->feed_recipe_id)
            ->assertJsonPath('data.cantidad_diaria_g', $manual ? '7777' : '2000');
    }

    /** @return array<string,array{string}> */
    public static function redistributionPaths(): array
    {
        return ['traslado' => ['total'], 'fraccionamiento' => ['split'], 'agrupación' => ['merge']];
    }

    // Flujo: todas las variantes de redistribución rechazan cambiar de UP.
    #[DataProvider('redistributionPaths')]
    public function test_cross_unit_movements_are_rejected_without_side_effects(string $kind): void
    {
        // Preparación: dispone un galpón en otra UP.
        $this->localTime('2026-10-05 12:00');
        $this->signIn();
        $flock = $this->admitted();
        $house = PoultryHouse::factory()->create(['production_unit_id' => ProductionUnit::factory()]);
        $payload = ['version' => 1, 'quantity' => $kind === 'total' ? 100 : 30, 'destination_poultry_house_id' => $house->id];
        if ($kind === 'split') {
            $payload['destination_code'] = 'CROSS-UP';
        }
        if ($kind === 'merge') {
            $receiver = $this->flock(20, Breed::query()->findOrFail($flock->breed_id), $house);
            $payload = ['version' => 1, 'quantity' => 30, 'destination_flock_id' => $receiver->public_id, 'destination_version' => 1];
        }

        // Request: verifica rechazo y censo intacto.
        $this->command('POST', '/flocks/'.$flock->public_id.'/redistributions', $payload)->assertConflict()
            ->assertJsonPath('message', 'No se permiten movimientos de lotes entre unidades productivas.');
        $this->assertSame(100, $flock->fresh()->current_quantity);
        $this->assertDatabaseCount('flock_movements', 1);
    }

    // Flujo: rectifica con recetas inactivas, compensa la información efectiva anterior y mantiene configuración e historia.
    public function test_corrections_compensate_latest_effective_values_without_mutating_daily_record_or_current_feeding(): void
    {
        // Preparación: registra un consumo original y una configuración manual posterior.
        $this->localTime('2026-10-05 12:00');
        $actor = $this->signIn(['flocks.manage', 'feed-recipes.manage']);
        $flock = $this->admitted();
        $this->localTime('2026-10-06 00:00');
        $consumption = $this->consume($flock, '2026-10-05');
        $this->command('POST', $this->feedingUrl($flock).'/manual', ['cantidad_diaria_g' => '9999'])->assertCreated();
        $configuration = FlockFeedingChange::query()->where('flock_id', $flock->id)->orderByDesc('id')->firstOrFail()->toArray();
        $inactive = FeedRecipe::factory()->create(['status' => FeedRecipeStatus::Inactive]);
        $path = '/lotes/'.$flock->public_id.'/consumos-alimento/'.$consumption->id.'/rectificaciones';
        $key = (string) Str::uuid();

        // Request: devuelve cuatro mil gramos y descuenta tres mil de otra receta.
        $payload = ['receta_id' => $inactive->id, 'cantidad_g' => '3000', 'motivo' => 'Corrección de receta'];
        $first = $this->command('POST', $path, $payload, $key)->assertCreated();
        $this->command('POST', $path, $payload, $key)->assertCreated()->assertExactJson($first->json());
        $this->assertDatabaseHas('feed_balances', ['feed_recipe_id' => $consumption->feed_recipe_id, 'quantity_grams' => 0]);
        $this->assertDatabaseHas('feed_balances', ['feed_recipe_id' => $inactive->id, 'quantity_grams' => -3000]);

        // Request: una rectificación posterior parte de tres mil y admite cero.
        $this->command('POST', $path, ['cantidad_g' => '0', 'motivo' => 'Consumo efectivo nulo'])->assertCreated();
        $this->command('POST', $path, $payload, $key)->assertCreated()->assertExactJson($first->json());
        $this->assertDatabaseHas('feed_balances', ['feed_recipe_id' => $inactive->id, 'quantity_grams' => 0]);
        $this->assertSame(4000, $consumption->fresh()->quantity_grams);
        $this->assertSame($configuration, FlockFeedingChange::query()->findOrFail($configuration['id'])->toArray());
        $this->assertDatabaseCount('feed_consumption_corrections', 2);
        $this->assertDatabaseCount('feed_consumption_movements', 4);
        $this->getJson('/api/v1/lotes/'.$flock->public_id.'/consumos-alimento/'.$consumption->id)->assertOk()
            ->assertJsonPath('data.cantidad_g', '0')->assertJsonPath('data.original.cantidad_g', '4000')->assertJsonCount(2, 'data.rectificaciones')->assertJsonCount(4, 'data.movimientos');
        $this->getJson('/api/v1/lotes/'.$flock->public_id.'/consumos-alimento')->assertOk()->assertJsonPath('acumulado_g', '0');
        $this->command('POST', $path, ['cantidad_g' => '10'])->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $this->command('POST', $path, ['cantidad_g' => '-1', 'motivo' => 'Error'])->assertUnprocessable();
    }

    // Flujo: auditoría fallida revierte consumo, compensación y configuración en sus transacciones.
    public function test_audit_failure_rolls_back_daily_consumption_and_its_stock(): void
    {
        // Preparación: dispone el lote y reemplaza el recorder sólo para simular una falla.
        $this->localTime('2026-10-05 12:00');
        $this->signIn();
        $flock = $this->admitted();
        $this->mock(AuditRecorder::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Auditoría caída'));

        // Mutación: la jornada pendiente falla al auditar.
        $this->localTime('2026-10-06 00:00');
        try {
            $this->consume($flock, '2026-10-05');
            $this->fail('La falla de auditoría debía propagarse.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Auditoría caída', $exception->getMessage());
        }
        $this->assertDatabaseCount('feed_consumptions', 0);
        $this->assertDatabaseCount('feed_consumption_movements', 0);
        $this->assertDatabaseCount('feed_balances', 0);
    }

    // Flujo: el límite real del saldo causa rollback sin redondear ni truncar.
    public function test_stock_storage_boundary_is_exact_and_atomic(): void
    {
        // Preparación: coloca el saldo en su límite negativo.
        $this->localTime('2026-10-05 12:00');
        $this->signIn();
        $flock = $this->admitted();
        $recipeId = FlockFeedingChange::query()->where('flock_id', $flock->id)->value('feed_recipe_id');
        FeedBalance::factory()->create(['feed_recipe_id' => $recipeId, 'production_unit_id' => $flock->production_unit_id, 'quantity_grams' => -999999999999]);

        // Mutación: rechaza superar el rango y conserva los valores exactos.
        $this->localTime('2026-10-06 00:00');
        try {
            $this->consume($flock, '2026-10-05');
            $this->fail('El saldo fuera de rango debía rechazarse.');
        } catch (LotsConflict $exception) {
            $this->assertStringContainsString('límite', $exception->getMessage());
        }
        $this->assertDatabaseCount('feed_consumptions', 0);
        $this->assertDatabaseHas('feed_balances', ['quantity_grams' => -999999999999]);
    }

    // Flujo: cada plan exige una alimentación inicial única, receta y gramos positivos.
    public function test_plan_requires_exactly_one_initial_feeding_and_positive_integer_grams(): void
    {
        // Preparación: crea una receta y un gestor de plantillas.
        $this->localTime('2026-10-05 12:00');
        $this->signIn(['management-plans.manage']);
        $recipe = FeedRecipe::factory()->create();
        $initial = ['type' => 'ration_change', 'title' => 'Inicio', 'timing_kind' => 'day', 'start_day' => 1, 'catalog_ref' => $recipe->id, 'grams_per_bird' => 40];
        $weighing = ['type' => 'weighing', 'title' => 'Pesaje', 'timing_kind' => 'day', 'start_day' => 1];

        // Request: rechaza ausencia, duplicado día/semana y cantidades inválidas.
        $this->command('POST', '/plantillas-manejo', ['name' => 'Sin inicio', 'activities' => [$weighing]])->assertConflict();
        $weekly = [...$initial, 'timing_kind' => 'week', 'start_week' => 1];
        unset($weekly['start_day']);
        $this->command('POST', '/plantillas-manejo', ['name' => 'Doble inicio', 'activities' => [$initial, $weekly]])->assertConflict();
        $this->command('POST', '/plantillas-manejo', ['name' => 'Cero', 'activities' => [[...$initial, 'grams_per_bird' => 0]]])->assertUnprocessable();
        $this->command('POST', '/plantillas-manejo', ['name' => 'Fracción', 'activities' => [[...$initial, 'grams_per_bird' => '40.5']]])->assertUnprocessable();
        $this->command('POST', '/plantillas-manejo', ['name' => 'Válido', 'activities' => [$weekly]])->assertCreated();
        $this->assertDatabaseCount('plan_templates', 1);
    }

    // Flujo: la revisión del plan sólo afecta días desde su fecha y respeta manual.
    public function test_plan_revision_changes_future_automatic_feeding_and_preserves_manual_configuration(): void
    {
        // Preparación: crea un lote el lunes y una nueva receta.
        $this->localTime('2026-10-05 12:00');
        $this->signIn(['flocks.manage', 'management-plans.manage']);
        $flock = $this->admitted();
        $recipe = FeedRecipe::factory()->create();
        $initial = ['type' => 'ration_change', 'title' => 'Plan revisado', 'timing_kind' => 'day', 'start_day' => 1, 'catalog_ref' => $recipe->id, 'grams_per_bird' => 80];

        // Request: revisa el miércoles sin cambiar días pendientes.
        $this->localTime('2026-10-07 12:00');
        $this->command('PATCH', '/flocks/'.$flock->public_id.'/plan-manejo', ['expected_revision' => 1, 'reason' => 'Nueva etapa', 'activities' => [$initial]])->assertOk();
        $this->localTime('2026-10-08 00:00');
        $this->assertSame(4000, $this->consume($flock, '2026-10-05')->quantity_grams);
        $this->assertSame(8000, $this->consume($flock, '2026-10-07')->quantity_grams);

        // Request: una nueva revisión conserva receta y cantidad del manual vigente.
        $this->command('POST', $this->feedingUrl($flock).'/manual', ['cantidad_diaria_g' => '1234'])->assertCreated();
        $this->command('PATCH', '/flocks/'.$flock->public_id.'/plan-manejo', ['expected_revision' => 2, 'reason' => 'Otra estimación', 'activities' => [[...$initial, 'grams_per_bird' => 110]]])->assertOk();
        $this->getJson('/api/v1'.$this->feedingUrl($flock))->assertOk()->assertJsonPath('data.modo', 'manual')->assertJsonPath('data.cantidad_diaria_g', '1234');
    }

    // Flujo: el alta rechaza ingresos retroactivos y el calendario se evalúa a medianoche de Montevideo.
    public function test_creation_date_and_scheduler_respect_montevideo_calendar(): void
    {
        // Preparación: crea un plan y referencia de alta.
        $this->localTime('2026-10-05 23:59');
        $actor = $this->signIn();
        $plan = $this->createPublishedPlanSelection($actor);
        $payload = [
            'code' => 'FEED-DATE', 'breed_id' => Breed::factory()->create()->id, 'origin' => 'Propio',
            'poultry_house_id' => PoultryHouse::factory()->create()->id, 'initial_quantity' => 100, ...$plan,
        ];

        // Request: rechaza días anteriores y posteriores al registro.
        $this->command('POST', '/flocks', [...$payload, 'entry_date' => '2026-10-04'])->assertConflict();
        $this->command('POST', '/flocks', [...$payload, 'entry_date' => '2026-10-06'])->assertConflict();
        $this->command('POST', '/flocks', [...$payload, 'entry_date' => '2026-10-05'])->assertCreated();
        $scheduled = collect(Schedule::events())->first(fn ($event): bool => str_contains($event->command ?? '', 'alimentacion:consumir'));
        $this->assertNotNull($scheduled);
        $this->assertFalse($scheduled->isDue(app()));

        // Consulta: sólo a las 03:00 UTC vence la jornada anterior local.
        $this->localTime('2026-10-06 00:00');
        $this->assertTrue($scheduled->isDue(app()));
        $this->artisan('alimentacion:consumir')->assertSuccessful();
        $this->assertDatabaseHas('feed_consumptions', ['consumption_date' => '2026-10-05', 'quantity_grams' => 4000]);
    }

    // Flujo: consultas y mutaciones requieren sesión, paginan y acotan filtros sin permisos nuevos.
    public function test_feeding_queries_are_authenticated_paginated_and_scoped_to_the_flock(): void
    {
        // Request: un cliente anónimo no accede a la alimentación.
        $this->getJson('/api/v1/lotes/'.Str::ulid().'/alimentacion')->assertUnauthorized();

        // Preparación: el gestor crea un lote con consumo y luego autentica a un usuario sin permisos específicos.
        $this->localTime('2026-10-05 12:00');
        $this->signIn();
        $flock = $this->admitted();
        $this->localTime('2026-10-06 00:00');
        $consumption = $this->consume($flock, '2026-10-05');
        $this->signIn([]);

        // Consulta: mantiene acceso autenticado y no revela consumos de otro lote.
        $this->getJson('/api/v1'.$this->feedingUrl($flock).'/historial?por_pagina=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1'.$this->feedingUrl($flock).'/historial?por_pagina=101')->assertUnprocessable();
        $this->getJson('/api/v1/lotes/'.$flock->public_id.'/consumos-alimento')->assertOk()->assertJsonPath('acumulado_g', '4000');
        $this->getJson('/api/v1/lotes/'.$flock->public_id.'/consumos-alimento?page=999')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('acumulado_g', '4000');
        $other = $this->flock();
        $this->getJson('/api/v1/lotes/'.$other->public_id.'/consumos-alimento/'.$consumption->id)->assertNotFound();
        $this->command('POST', $this->feedingUrl($flock).'/manual', ['cantidad_diaria_g' => 1000])->assertCreated();
    }

    // Flujo: las compensaciones y los cambios manuales revierten todos sus efectos cuando falla el recorder.
    public function test_audit_failure_rolls_back_correction_and_manual_configuration(): void
    {
        // Preparación: conserva un consumo y la configuración vigente antes de simular fallas.
        $this->localTime('2026-10-05 12:00');
        $actor = $this->signIn();
        $flock = $this->admitted();
        $this->localTime('2026-10-06 12:00');
        $consumption = $this->consume($flock, '2026-10-05');
        $this->mock(AuditRecorder::class)->shouldReceive('record')->twice()->andThrow(new RuntimeException('Auditoría no disponible'));

        // Mutación: las dos Actions propagan la falla sin conservar efectos parciales.
        foreach ([
            fn () => app(CorrectFeedConsumptionAction::class)->execute($consumption, ['cantidad_g' => '1000', 'motivo' => 'Recuento', 'idempotency_key' => (string) Str::uuid()], $actor),
            fn () => app(ChangeFlockFeedingAction::class)->execute($flock, ['cantidad_diaria_g' => '1000', 'idempotency_key' => (string) Str::uuid()], $actor),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('La operación debía fallar al auditar.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Auditoría no disponible', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('feed_consumption_corrections', 0);
        $this->assertDatabaseCount('feed_consumption_movements', 1);
        $this->assertDatabaseCount('flock_feeding_changes', 1);
        $this->assertDatabaseHas('feed_balances', ['quantity_grams' => -4000]);
    }

    // Flujo: la plantilla editada no cambia las estimaciones copiadas al lote.
    public function test_template_edits_do_not_change_the_flock_feeding_copy(): void
    {
        // Preparación: crea el lote y conserva la receta asignada.
        $this->localTime('2026-10-05 12:00');
        $this->signIn(['flocks.manage', 'management-plans.manage']);
        $flock = $this->admitted();
        $configuration = FlockFeedingChange::query()->where('flock_id', $flock->id)->firstOrFail();
        $template = PlanTemplate::query()->sole();
        $recipe = FeedRecipe::factory()->create();

        // Request: publica otra receta y estimación en la plantilla.
        $this->command('PATCH', '/plantillas-manejo/'.$template->public_id, [
            'expected_version' => 1, 'activities' => [[
                'type' => 'ration_change', 'title' => 'Nueva plantilla', 'timing_kind' => 'day', 'start_day' => 1,
                'catalog_ref' => $recipe->id, 'grams_per_bird' => 110,
            ]],
        ])->assertOk();
        $this->command('POST', '/plantillas-manejo/'.$template->public_id.'/publicacion', ['expected_version' => 2])->assertOk();

        // Mutación: el consumo conserva cuarenta gramos por ave y su receta original.
        $this->localTime('2026-10-06 00:00');
        $consumption = $this->consume($flock, '2026-10-05');
        $this->assertSame(4000, $consumption->quantity_grams);
        $this->assertSame($configuration->feed_recipe_id, $consumption->feed_recipe_id);
    }

    // Flujo: volver a automático salta etapas inactivas y mantiene la receta y gramos de la etapa anterior.
    public function test_return_to_automatic_skips_inactive_current_stage(): void
    {
        // Preparación: asigna una etapa futura y entra en manual.
        $this->localTime('2026-10-05 12:00');
        $actor = $this->signIn(['flocks.manage', 'feed-recipes.manage']);
        $initial = FeedRecipe::factory()->create();
        $future = FeedRecipe::factory()->create();
        $flock = $this->admitted(stages: [['day' => 1, 'grams' => 40, 'recipe' => $initial], ['day' => 3, 'grams' => 80, 'recipe' => $future]]);
        $this->command('POST', $this->feedingUrl($flock).'/manual', ['cantidad_diaria_g' => '9999'])->assertCreated();
        app(ChangeFeedRecipeStatusAction::class)->execute($future, FeedRecipeStatus::Inactive, $actor);

        // Request: retoma el plan cuando la etapa actual ya está desactivada.
        $this->localTime('2026-10-07 12:00');
        $this->command('POST', $this->feedingUrl($flock).'/automatico', [])->assertCreated();
        $this->getJson('/api/v1'.$this->feedingUrl($flock))->assertOk()
            ->assertJsonPath('data.receta_id', $initial->id)->assertJsonPath('data.gramos_por_ave', '40')->assertJsonPath('data.cantidad_diaria_g', '4000');
    }

    // Flujo: el acceso compartido mantiene credencial, sesión y generación vigentes para todas las consultas nuevas.
    public function test_shared_session_requires_complete_current_device_context(): void
    {
        // Preparación: crea el lote y una sesión nativa compartida real.
        $this->localTime('2026-10-05 12:00');
        $actor = $this->signIn();
        $flock = $this->admitted();
        $secret = Str::random(64);
        $device = SharedDevice::query()->create([
            'name' => 'Tablet alimentación', 'credential_hash' => hash('sha256', $secret),
            'credential_expires_at' => now()->addYear(), 'enrolled_by' => $actor->id, 'enrolled_at' => now(),
        ]);
        $token = $actor->createToken('shared-feeding', ['*'], now()->addHours(8));
        $session = app(AuthSessionService::class)->createShared($actor, $device->refresh(), $token);
        $headers = ['Authorization' => 'Bearer '.$token->plainTextToken, 'X-Shared-Device-Token' => $device->id.'|'.$secret, 'X-GAM-Session' => $session->id];
        $this->app['auth']->forgetGuards();

        // Request: sólo el contexto completo obtiene la configuración.
        $this->getJson('/api/v1'.$this->feedingUrl($flock), $headers)->assertOk();
        $missing = $headers;
        unset($missing['X-Shared-Device-Token']);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1'.$this->feedingUrl($flock), $missing)->assertUnauthorized();

        // Mutación: una generación distinta invalida inmediatamente la sesión anterior.
        $device->increment('session_generation');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1'.$this->feedingUrl($flock), $headers)->assertUnauthorized();
    }

    // Flujo: el endpoint de manejo existente configura alimentación manual con recetas sin registrar comidas.
    public function test_existing_ration_change_endpoint_updates_configuration_and_keeps_its_permission(): void
    {
        // Preparación: asigna un plan y autentica a un ejecutor de manejos.
        $this->localTime('2026-10-05 12:00');
        $this->signIn(['flocks.manage', 'management-plans.execute']);
        $flock = $this->admitted();
        $recipe = FeedRecipe::factory()->create();

        // Request: registra la configuración del día mediante el contrato adaptado.
        $this->command('POST', '/flocks/'.$flock->public_id.'/cambios-racion', [
            'ration_description' => 'Configuración manual', 'feed_recipe_id' => $recipe->id,
            'daily_quantity_grams' => '5432', 'occurred_at' => now()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.ration_change.daily_quantity_grams', '5432');
        $this->getJson('/api/v1'.$this->feedingUrl($flock))->assertOk()->assertJsonPath('data.modo', 'manual')->assertJsonPath('data.cantidad_diaria_g', '5432');
        $this->assertDatabaseCount('feed_consumptions', 0);

        // Request: el permiso anterior de manejos sigue protegiendo ese endpoint.
        $this->signIn([]);
        $this->command('POST', '/flocks/'.$flock->public_id.'/cambios-racion', [])->assertForbidden();
    }

    // Flujo: el cálculo usa enteros arbitrarios y el registro respeta almacenamiento sin impedir una configuración manual válida.
    public function test_automatic_product_overflow_is_exact_and_can_be_resolved_with_manual_configuration(): void
    {
        // Preparación: asigna una estimación cuyo producto supera el rango persistible.
        $this->localTime('2026-10-05 12:00');
        $this->signIn();
        $flock = $this->admitted(stages: [['day' => 1, 'grams' => 999999999999]]);
        $this->getJson('/api/v1'.$this->feedingUrl($flock))->assertOk()->assertJsonPath('data.cantidad_diaria_g', '99999999999900');

        // Mutación: el proceso no trunca el cálculo ni deja efectos parciales.
        $this->localTime('2026-10-06 00:00');
        try {
            $this->consume($flock, '2026-10-05');
            $this->fail('El consumo fuera del rango debía rechazarse.');
        } catch (LotsConflict $exception) {
            $this->assertStringContainsString('límite', $exception->getMessage());
        }
        $this->assertDatabaseCount('feed_consumptions', 0);
        $this->assertDatabaseCount('feed_balances', 0);

        // Request: el operador puede fijar un total válido para jornadas futuras.
        $this->command('POST', $this->feedingUrl($flock).'/manual', ['cantidad_diaria_g' => '1000'])->assertCreated();
        $this->getJson('/api/v1'.$this->feedingUrl($flock))->assertOk()->assertJsonPath('data.cantidad_diaria_g', '1000');
    }

    // Flujo: el contrato describe los campos que realmente entrega la configuración y los filtros acotados.
    public function test_feeding_response_agrees_with_its_openapi_contract(): void
    {
        // Preparación: crea un lote y lee el contrato publicado del bloque.
        $this->localTime('2026-10-05 12:00');
        $this->signIn();
        $flock = $this->admitted();
        $contract = Yaml::parseFile(base_path('contracts/openapi/flock-feeding.yaml'));

        // Consulta: compara la respuesta efectiva con los campos requeridos de la documentación.
        $response = $this->getJson('/api/v1'.$this->feedingUrl($flock))->assertOk();
        $this->assertEqualsCanonicalizing($contract['components']['schemas']['Feeding']['required'], array_keys($response->json('data')));
        $this->assertSame(100, $contract['components']['parameters']['PerPage']['schema']['maximum']);
    }

    /** @return array<string,array{string}> */
    public static function transitionDayDeactivationTimes(): array
    {
        return ['después de medianoche' => ['01:00'], 'al mediodía' => ['12:00']];
    }

    // Flujo: desactivar durante la jornada no revierte una etapa que estaba disponible al comenzar el día.
    #[DataProvider('transitionDayDeactivationTimes')]
    public function test_recipe_deactivated_during_transition_day_remains_the_effective_stage_for_recovery(string $time): void
    {
        // Preparación: asigna una segunda etapa que comienza el martes.
        $this->localTime('2026-10-05 12:00');
        $actor = $this->signIn(['flocks.manage', 'feed-recipes.manage']);
        $recipe = FeedRecipe::factory()->create();
        $flock = $this->admitted(stages: [['day' => 1, 'grams' => 40], ['day' => 2, 'grams' => 60, 'recipe' => $recipe]]);

        // Mutación: desactiva la receta después del inicio de su etapa.
        $this->localTime('2026-10-06 '.$time);
        app(ChangeFeedRecipeStatusAction::class)->execute($recipe, FeedRecipeStatus::Inactive, $actor);
        $this->getJson('/api/v1'.$this->feedingUrl($flock))->assertOk()->assertJsonPath('data.receta_id', $recipe->id)->assertJsonPath('data.cantidad_diaria_g', '6000');

        // Mutación: la recuperación conserva tanto la etapa inicial como la nueva ya vigente.
        $this->localTime('2026-10-08 00:00');
        $this->assertSame(4000, $this->consume($flock, '2026-10-05')->quantity_grams);
        $tuesday = $this->consume($flock, '2026-10-06');
        $this->assertSame($recipe->id, $tuesday->feed_recipe_id);
        $this->assertSame(6000, $tuesday->quantity_grams);
        $this->assertSame(6000, $this->consume($flock, '2026-10-07')->quantity_grams);
    }
}
