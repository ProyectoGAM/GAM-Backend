<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\AdjustFeedBalanceAction;
use App\Actions\Inventory\CancelFeedManufactureAction;
use App\Actions\Inventory\ManufactureFeedAction;
use App\Enums\Inventory\FeedBalanceStatus;
use App\Events\Inventory\FeedBalanceEvaluated;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\Inventory\FeedBalance;
use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\FeedRecipeIngredient;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use App\Services\Inventory\FeedBalanceEvaluator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FeedBalanceEvaluationTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: elaboración, ajuste y cancelación evalúan el saldo sin modificar el mínimo ni repetir señales al reintentar.
    public function test_stock_operations_evaluate_the_result_and_idempotent_replays_do_not_repeat_events(): void
    {
        // Preparación: crea una receta de cien gramos con ingrediente suficiente y mínimo ya configurado.
        $actor = User::factory()->create();
        $recipe = FeedRecipe::factory()->create(['yield_grams' => 100]);
        $product = Product::factory()->rawMaterial()->create();
        FeedRecipeIngredient::factory()->create(['feed_recipe_id' => $recipe->id, 'product_id' => $product->id, 'quantity_grams' => 100]);
        $plant = PoultryHouse::factory()->feed()->create();
        $location = StockLocation::factory()->create(['production_unit_id' => $plant->production_unit_id,
            'poultry_house_id' => $plant->id, 'system_managed' => true]);
        StockBalance::factory()->create(['product_id' => $product->id, 'stock_location_id' => $location->id, 'on_hand_quantity' => 1000]);
        $balance = FeedBalance::factory()->create(['feed_recipe_id' => $recipe->id, 'production_unit_id' => $plant->production_unit_id,
            'minimum_quantity_grams' => 100]);
        Event::fake([FeedBalanceEvaluated::class]);
        $key = (string) Str::uuid();

        // Mutación: elabora cien gramos y repite el mismo comando idempotente.
        $manufacture = app(ManufactureFeedAction::class)->execute($recipe->id, $plant->id, 100, $key, $actor);
        app(ManufactureFeedAction::class)->execute($recipe->id, $plant->id, 100, $key, $actor);
        Event::assertDispatched(FeedBalanceEvaluated::class, 1);
        Event::assertDispatched(FeedBalanceEvaluated::class, fn (FeedBalanceEvaluated $event): bool => $event->reason === 'manufacture' && $event->quantityGrams === 100
            && $event->status === FeedBalanceStatus::AtMinimum && $event->operationId === $manufacture->operation_id);

        // Mutación: suma alimento de forma independiente y evalúa la recuperación del mínimo.
        $adjustment = app(AdjustFeedBalanceAction::class)->execute($recipe->id, $plant->production_unit_id, 'sumar', 100,
            'Recuento', (string) Str::uuid(), $actor);
        Event::assertDispatched(FeedBalanceEvaluated::class, fn (FeedBalanceEvaluated $event): bool => $event->reason === 'feed_adjustment' && $event->quantityGrams === 200
            && $event->status === FeedBalanceStatus::Sufficient && $event->operationId === $adjustment->operation_id);

        // Mutación: cancela sólo la elaboración y repite su compensación con la misma clave.
        $cancelKey = (string) Str::uuid();
        $cancellation = app(CancelFeedManufactureAction::class)->execute($manufacture->id, 'Corrección', $cancelKey, $actor);
        app(CancelFeedManufactureAction::class)->execute($manufacture->id, 'Corrección', $cancelKey, $actor);
        Event::assertDispatched(FeedBalanceEvaluated::class, 3);
        Event::assertDispatched(FeedBalanceEvaluated::class, fn (FeedBalanceEvaluated $event): bool => $event->reason === 'cancellation' && $event->quantityGrams === 100
            && $event->minimumQuantityGrams === 100 && $event->operationId === $cancellation->operation_id);
        $this->assertSame(100, $balance->fresh()->minimum_quantity_grams);
        $this->assertSame(100, $balance->fresh()->quantity_grams);
    }

    // Flujo: las evaluaciones esperan el commit y conservan una instantánea aunque el objeto cambie después.
    public function test_evaluation_is_dispatched_after_commit_with_an_immutable_snapshot(): void
    {
        // Preparación: mantiene una cuenta suficiente y abre una transacción adicional.
        $balance = FeedBalance::factory()->create(['quantity_grams' => 800000, 'minimum_quantity_grams' => 300000]);
        Event::fake([FeedBalanceEvaluated::class]);
        $operationId = (string) Str::uuid();
        DB::beginTransaction();

        // Mutación: evalúa el saldo y cambia sólo la instancia en memoria antes de confirmar.
        app(FeedBalanceEvaluator::class)->evaluate($balance, 'feed_adjustment', $operationId);
        $balance->forceFill(['quantity_grams' => 0, 'minimum_quantity_grams' => null]);
        Event::assertNotDispatched(FeedBalanceEvaluated::class);
        DB::commit();

        // Verificación: el evento publicado conserva el saldo y mínimo originales de la evaluación.
        Event::assertDispatched(FeedBalanceEvaluated::class, fn (FeedBalanceEvaluated $event): bool => $event->balanceId === $balance->id && $event->quantityGrams === 800000
            && $event->minimumQuantityGrams === 300000 && $event->status === FeedBalanceStatus::Sufficient);
    }

    // Flujo: un rollback descarta señales incluso cuando la evaluación ya se había solicitado.
    public function test_rolled_back_evaluations_are_not_dispatched(): void
    {
        // Preparación: configura una cuenta que requiere atención y comienza una transacción.
        $balance = FeedBalance::factory()->create(['quantity_grams' => 200000, 'minimum_quantity_grams' => 250000]);
        Event::fake([FeedBalanceEvaluated::class]);
        DB::beginTransaction();

        // Mutación: solicita la evaluación y revierte la transacción que la originó.
        app(FeedBalanceEvaluator::class)->evaluate($balance, 'consumption', (string) Str::uuid());
        DB::rollBack();

        // Verificación: ningún consumidor futuro de alertas recibe una operación revertida.
        Event::assertNotDispatched(FeedBalanceEvaluated::class);
    }
}
