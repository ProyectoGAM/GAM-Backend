<?php

namespace Tests\Feature\Inventory;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\FeedStockOperation;
use App\Models\Lots\Flock;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FeedPermissionTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function permissions(): array
    {
        return [
            'consulta' => ['can_view_feed_stock', 'viewAny'],
            'elaboración' => ['can_manufacture_feed', 'manufacture'],
            'ajuste de alimento' => ['can_adjust_feed_stock', 'adjustFeed'],
            'ajuste de ingrediente' => ['can_adjust_feed_ingredients', 'adjustIngredient'],
            'cancelación' => ['can_cancel_feed_manufacturing', 'cancel'],
            'mínimos' => ['can_manage_feed_minimums', 'manageMinimums'],
            'consulta de alimentación' => ['can_view_flock_feeding', 'viewFeeding'],
            'configuración de alimentación' => ['can_manage_flock_feeding', 'manageFeeding'],
            'rectificación' => ['can_correct_feed_consumption', 'correctConsumption'],
        ];
    }

    // Flujo: cada permiso autoriza únicamente su capacidad y no otras operaciones sensibles.
    #[DataProvider('permissions')]
    public function test_policy_distinguishes_each_feed_capability(string $permission, string $ability): void
    {
        // Preparación: activa un solo permiso del catálogo en el rol de prueba.
        $this->seed(IdentityPermissionSeeder::class);
        $user = User::factory()->create();
        $user->roles()->firstOrFail()->syncPermissions([$permission]);

        // Consultas: verifica la matriz de capacidades mediante el Gate de Laravel.
        foreach (['viewAny', 'manufacture', 'adjustFeed', 'adjustIngredient', 'cancel', 'manageMinimums', 'viewFeeding', 'manageFeeding', 'correctConsumption'] as $candidate) {
            $this->assertSame($ability === $candidate, Gate::forUser($user)->allows($candidate, FeedStockOperation::class), $candidate);
        }
    }

    // Flujo: la autenticación sola deja de habilitar alimento y alimentación de lotes.
    public function test_authenticated_users_without_feed_permissions_receive_403(): void
    {
        // Preparación: utiliza una cuenta sin permisos y recursos existentes para alcanzar la autorización.
        $user = User::factory()->create();
        $operation = FeedStockOperation::factory()->create(['kind' => 'manufacture']);
        $flock = Flock::factory()->create();
        Sanctum::actingAs($user);

        // Requests: rechaza lecturas y mutaciones antes de validar sus payloads.
        foreach (['/elaboraciones', '/saldos-alimento', '/ajustes-alimentacion', '/lotes/'.$flock->public_id.'/alimentacion'] as $url) {
            $this->getJson('/api/v1'.$url)->assertForbidden();
        }
        $this->postJson('/api/v1/elaboraciones', [], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
        $this->postJson('/api/v1/elaboraciones/'.$operation->id.'/cancelacion', [], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
        $this->postJson('/api/v1/lotes/'.$flock->public_id.'/alimentacion/manual', [], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
    }

    // Flujo: ajustar alimento no concede ajustes de ingredientes ni elaboración o cancelación.
    public function test_feed_adjuster_can_change_feed_balance_but_cannot_adjust_ingredients(): void
    {
        // Preparación: activa lectura y ajuste de alimento en un rol no administrador.
        $this->seed(IdentityPermissionSeeder::class);
        $user = User::factory()->create();
        $user->roles()->firstOrFail()->syncPermissions(['can_view_feed_stock', 'can_adjust_feed_stock']);
        $recipe = FeedRecipe::factory()->create();
        $plant = PoultryHouse::factory()->feed()->create();
        $product = Product::factory()->rawMaterial()->create();
        Sanctum::actingAs($user);
        $payload = ['accion' => 'sumar', 'valor_g' => 100, 'motivo' => 'Recuento autorizado'];

        // Requests: acredita alimento sin exigir permisos de inventario manual.
        $this->getJson('/api/v1/saldos-alimento')->assertOk();
        $this->postJson('/api/v1/unidades-productivas/'.$plant->production_unit_id.'/recetas/'.$recipe->id.'/ajustes', $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->assertDatabaseHas('feed_balances', ['feed_recipe_id' => $recipe->id, 'production_unit_id' => $plant->production_unit_id, 'quantity_grams' => 100]);
        $this->postJson('/api/v1/plantas-racion/'.$plant->id.'/ingredientes/'.$product->id.'/ajustes', $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
        $this->postJson('/api/v1/elaboraciones', [], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('feed_stock_operations', 1);
    }
}
