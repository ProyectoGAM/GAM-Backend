<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\AdjustFeedBalanceAction;
use App\Actions\Inventory\CancelFeedManufactureAction;
use App\Actions\Inventory\ManufactureFeedAction;
use App\Actions\Inventory\SetFeedBalanceMinimumAction;
use App\Exceptions\Inventory\FeedRecipeConflict;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\FeedRecipeIngredient;
use App\Models\Inventory\FeedStockOperation;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FeedManufactureConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private ?string $originalAppKey = null;

    private bool $hadOriginalEnvAppKey = false;

    private ?string $originalEnvAppKey = null;

    private bool $hadOriginalServerAppKey = false;

    private ?string $originalServerAppKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalAppKey = getenv('APP_KEY') === false ? null : getenv('APP_KEY');
        $this->hadOriginalEnvAppKey = array_key_exists('APP_KEY', $_ENV);
        $this->originalEnvAppKey = $_ENV['APP_KEY'] ?? null;
        $this->hadOriginalServerAppKey = array_key_exists('APP_KEY', $_SERVER);
        $this->originalServerAppKey = $_SERVER['APP_KEY'] ?? null;
        $applicationKey = (string) config('app.key');
        putenv('APP_KEY='.$applicationKey);
        $_ENV['APP_KEY'] = $applicationKey;
        $_SERVER['APP_KEY'] = $applicationKey;
        putenv('APP_ENV=testing');
        putenv('DB_CONNECTION=pgsql');
        putenv('DB_HOST=postgres');
        putenv('DB_DATABASE='.DB::connection()->getDatabaseName());
    }

    protected function tearDown(): void
    {
        if ($this->originalAppKey === null) {
            putenv('APP_KEY');
        } else {
            putenv('APP_KEY='.$this->originalAppKey);
        }
        if ($this->hadOriginalEnvAppKey) {
            $_ENV['APP_KEY'] = $this->originalEnvAppKey;
        } else {
            unset($_ENV['APP_KEY']);
        }
        if ($this->hadOriginalServerAppKey) {
            $_SERVER['APP_KEY'] = $this->originalServerAppKey;
        } else {
            unset($_SERVER['APP_KEY']);
        }

        parent::tearDown();
    }

    /** Las réplicas necesitan una base PostgreSQL de pruebas completamente migrada. */
    public function runDatabaseMigrations(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'pgsql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_testing')) {
            $this->markTestSkipped('La concurrencia real requiere una base PostgreSQL aislada con sufijo _testing.');
        }

        $this->migrateIsolatedDatabase();
    }

    // Flujo: dos reintentos simultáneos registran una sola elaboración y su auditoría.
    public function test_duplicate_keys_produce_one_manufacture_and_one_credit(): void
    {
        // Preparación: comparte receta, planta, actor y clave entre procesos.
        $data = $this->fixture();
        $recipeId = $data['recipe']->id;
        $houseId = $data['plants'][0]->id;
        $actorId = $data['actor']->id;
        $key = (string) Str::uuid();

        // Mutación: los procesos compiten por registrar exactamente el mismo pedido.
        $task = static fn (): int => (int) app(ManufactureFeedAction::class)->execute($recipeId, $houseId, 150, $key, User::query()->findOrFail($actorId))->id;
        $results = Concurrency::driver('process')->run([$task, $task], timeout: 30);
        $this->assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('feed_stock_operations', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('feed_balances', ['quantity_grams' => 150]);
        $this->assertDatabaseCount('activity_log', 2);
    }

    // Flujo: actores y recetas diferentes consumen las mismas plantas sin doble consumo ni locks cruzados.
    public function test_opposite_selected_plants_cannot_double_consume_shared_ingredients(): void
    {
        // Preparación: ambas plantas juntas alcanzan sólo para una elaboración.
        $data = $this->fixture();
        $firstRecipeId = $data['recipe']->id;
        $otherActor = User::factory()->create();
        $secondRecipe = FeedRecipe::factory()->create(['created_by' => $otherActor->id, 'yield_grams' => 150]);
        FeedRecipeIngredient::factory()->create(['feed_recipe_id' => $secondRecipe->id, 'product_id' => $data['product']->id, 'quantity_grams' => 150]);
        $firstActorId = $data['actor']->id;
        $secondActorId = $otherActor->id;
        $secondRecipeId = $secondRecipe->id;
        $firstHouseId = $data['plants'][0]->id;
        $secondHouseId = $data['plants'][1]->id;

        // Mutación: cada proceso selecciona una planta diferente y necesita ambas.
        $first = static function () use ($firstRecipeId, $firstHouseId, $firstActorId): string {
            try {
                app(ManufactureFeedAction::class)->execute($firstRecipeId, $firstHouseId, 150, (string) Str::uuid(), User::query()->findOrFail($firstActorId));

                return 'success';
            } catch (FeedRecipeConflict) {
                return 'insufficient';
            }
        };
        $second = static function () use ($secondRecipeId, $secondHouseId, $secondActorId): string {
            try {
                app(ManufactureFeedAction::class)->execute($secondRecipeId, $secondHouseId, 150, (string) Str::uuid(), User::query()->findOrFail($secondActorId));

                return 'success';
            } catch (FeedRecipeConflict) {
                return 'insufficient';
            }
        };
        $results = Concurrency::driver('process')->run([$first, $second], timeout: 30);
        sort($results);
        $this->assertSame(['insufficient', 'success'], $results);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('feed_stock_operations', 1);
        $this->assertSame('50.000000', (string) StockBalance::query()->sum('on_hand_quantity'));
        $this->assertSame(0, StockBalance::query()->where('on_hand_quantity', '<', 0)->count());
    }

    // Flujo: cancelaciones concurrentes con diferentes claves sólo devuelven los ingredientes una vez.
    public function test_concurrent_cancellations_have_only_one_winner(): void
    {
        // Preparación: crea una elaboración visible para ambos operadores.
        $data = $this->fixture();
        $original = app(ManufactureFeedAction::class)->execute($data['recipe']->id, $data['plants'][0]->id, 150, (string) Str::uuid(), $data['actor']);
        $id = $original->id;
        $firstActorId = $data['actor']->id;
        $secondActorId = User::factory()->create()->id;

        // Mutación: los dos operadores intentan cancelar la misma elaboración.
        $first = static function () use ($id, $firstActorId): string {
            try {
                app(CancelFeedManufactureAction::class)->execute($id, 'Corrección', (string) Str::uuid(), User::query()->findOrFail($firstActorId));

                return 'success';
            } catch (FeedRecipeConflict) {
                return 'already_cancelled';
            }
        };
        $second = static function () use ($id, $secondActorId): string {
            try {
                app(CancelFeedManufactureAction::class)->execute($id, 'Corrección', (string) Str::uuid(), User::query()->findOrFail($secondActorId));

                return 'success';
            } catch (FeedRecipeConflict) {
                return 'already_cancelled';
            }
        };
        $results = Concurrency::driver('process')->run([$first, $second], timeout: 30);
        sort($results);
        $this->assertSame(['already_cancelled', 'success'], $results);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertSame(1, FeedStockOperation::query()->where('kind', 'cancellation')->count());
        $this->assertSame('200.000000', (string) StockBalance::query()->sum('on_hand_quantity'));
        $this->assertDatabaseHas('feed_balances', ['quantity_grams' => 0]);
    }

    // Flujo: créditos concurrentes no pierden actualizaciones al crear la cuenta receta/UP.
    public function test_concurrent_adjustments_accumulate_without_lost_updates(): void
    {
        // Preparación: comparte una cuenta todavía inexistente entre dos operadores.
        $data = $this->fixture();
        $recipeId = $data['recipe']->id;
        $unitId = $data['plants'][0]->production_unit_id;
        $firstActorId = $data['actor']->id;
        $secondActorId = User::factory()->create()->id;

        // Mutación: registra dos créditos independientes.
        $first = static fn (): int => (int) app(AdjustFeedBalanceAction::class)->execute($recipeId, $unitId, 'sumar', 10, 'Recuento', (string) Str::uuid(), User::query()->findOrFail($firstActorId))->id;
        $second = static fn (): int => (int) app(AdjustFeedBalanceAction::class)->execute($recipeId, $unitId, 'sumar', 20, 'Recuento', (string) Str::uuid(), User::query()->findOrFail($secondActorId))->id;
        $results = Concurrency::driver('process')->run([$first, $second], timeout: 30);
        $this->assertNotSame($results[0], $results[1]);
        $this->assertDatabaseCount('feed_balances', 1);
        $this->assertDatabaseHas('feed_balances', ['quantity_grams' => 30]);
        $this->assertDatabaseCount('feed_stock_operations', 2);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    // Flujo: configurar el mínimo y acreditar stock simultáneamente materializa una sola cuenta sin perder atributos.
    public function test_minimum_configuration_and_stock_credit_share_the_same_locked_account(): void
    {
        // Preparación: usa una cuenta inexistente que será creada por dos procesos.
        $data = $this->fixture();
        $recipeId = $data['recipe']->id;
        $unitId = $data['plants'][0]->production_unit_id;
        $actorId = $data['actor']->id;

        // Mutación: configura un mínimo mientras otro proceso registra alimento.
        $minimum = static fn (): int => (int) app(SetFeedBalanceMinimumAction::class)->execute(
            $recipeId, $unitId, 250000, User::query()->findOrFail($actorId))->id;
        $credit = static fn (): int => (int) app(AdjustFeedBalanceAction::class)->execute(
            $recipeId, $unitId, 'sumar', 200000, 'Recuento concurrente', (string) Str::uuid(), User::query()->findOrFail($actorId))->id;
        Concurrency::driver('process')->run([$minimum, $credit], timeout: 30);

        // Consulta: una única fila conserva el crédito y el mínimo independientemente del orden de llegada.
        $this->assertDatabaseCount('feed_balances', 1);
        $this->assertDatabaseHas('feed_balances', ['feed_recipe_id' => $recipeId, 'production_unit_id' => $unitId,
            'quantity_grams' => 200000, 'minimum_quantity_grams' => 250000]);
        $this->assertDatabaseCount('feed_stock_operations', 1);
        $this->assertDatabaseHas('activity_log', ['event' => 'feed_balance_minimum_changed']);
    }

    /** @return array{actor:User, recipe:FeedRecipe, product:Product, plants:list<PoultryHouse>} */
    private function fixture(): array
    {
        $actor = User::factory()->create();
        $recipe = FeedRecipe::factory()->create(['created_by' => $actor->id, 'yield_grams' => 150]);
        $product = Product::factory()->rawMaterial()->create();
        FeedRecipeIngredient::factory()->create(['feed_recipe_id' => $recipe->id, 'product_id' => $product->id, 'quantity_grams' => 150]);
        $first = PoultryHouse::factory()->feed()->create();
        $plants = [$first, PoultryHouse::factory()->feed()->create(['production_unit_id' => $first->production_unit_id])];
        foreach ($plants as $plant) {
            $location = StockLocation::factory()->create(['production_unit_id' => $plant->production_unit_id, 'poultry_house_id' => $plant->id, 'system_managed' => true]);
            StockBalance::factory()->create(['product_id' => $product->id, 'stock_location_id' => $location->id, 'on_hand_quantity' => '100', 'allow_negative' => true]);
        }

        return compact('actor', 'recipe', 'product', 'plants');
    }
}
