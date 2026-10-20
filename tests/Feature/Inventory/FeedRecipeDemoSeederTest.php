<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\StockBalance;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Inventory\FeedRecipeDemoSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class FeedRecipeDemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: la entrada oficial crea la receta demo con productos existentes y su reintento no cambia stock.
    public function test_local_demo_is_registered_audited_and_idempotent(): void
    {
        // Preparación: habilita demo local y aísla los archivos de reportes.
        $this->app->instance('env', 'local');
        Storage::fake('local');

        // Acción: ejecuta la entrada oficial de datos y conserva los resultados.
        $this->seed(DatabaseSeeder::class);
        $recipe = FeedRecipe::query()->with('ingredients')->sole();
        $this->assertSame('Ración demo inicial', $recipe->name);
        $this->assertSame(13000, $recipe->yield_grams);
        $this->assertSame([5000, 7000], $recipe->ingredients->pluck('quantity_grams')->all());
        $balances = StockBalance::query()->orderBy('id')->get()->toArray();
        $recipeBefore = $recipe->toArray();
        $auditBefore = AuditEntry::query()->where('event', 'feed_recipe_created')->sole()->toArray();

        // Acción: repite la carga de recetas sin elaborar ni sobrescribir datos.
        $this->seed(FeedRecipeDemoSeeder::class);
        $this->assertSame($recipeBefore, FeedRecipe::query()->with('ingredients')->sole()->toArray());
        $this->assertSame($balances, StockBalance::query()->orderBy('id')->get()->toArray());
        $this->assertSame($auditBefore, AuditEntry::query()->where('event', 'feed_recipe_created')->sole()->toArray());
    }

    // Flujo: omite datos ficticios fuera de los ambientes autorizados.
    public function test_demo_is_skipped_in_testing(): void
    {
        // Acción: invoca el seeder protegido por ambiente.
        $this->seed(FeedRecipeDemoSeeder::class);

        // Consulta: no crea recetas ni productos.
        $this->assertDatabaseCount('feed_recipes', 0);
        $this->assertDatabaseCount('products', 0);
    }
}
