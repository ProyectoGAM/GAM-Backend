<?php

namespace Tests\Feature\Inventory;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class EggStockEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @param list<string> $permissions */
    private function signIn(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Sanctum::actingAs($user, ['api:access']);

        return $user;
    }

    /** @param array<string, mixed> $payload */
    private function command(string $method, string $path, array $payload): TestResponse
    {
        return $this->json($method, '/api/v1'.$path, $payload, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    /** Flujo: registra ingreso manual, salida y pérdida en la cuenta corriente de una UP. */
    public function test_manual_receipt_issue_and_loss_update_integer_balance(): void
    {
        // Preparación: autentica al administrador y selecciona una UP activa.
        $this->signIn(['egg-stock.view', 'egg-stock.move', 'egg-stock.adjust']);
        $unit = ProductionUnit::factory()->create();

        // Requests: registra ingreso manual y dos egresos especializados.
        $this->command('POST', "/unidades-productivas/{$unit->id}/stock-huevos/ingresos", ['cantidad' => 10, 'motivo' => 'Carga manual'])->assertCreated();
        $this->command('POST', "/unidades-productivas/{$unit->id}/stock-huevos/salidas", ['cantidad' => 15, 'tipo' => 'loss', 'motivo' => 'Rotura'])->assertCreated();

        // Consulta: confirma que las cuentas de huevo permiten saldo negativo.
        $this->getJson("/api/v1/unidades-productivas/{$unit->id}/stock-huevos")->assertOk()->assertJsonPath('data.saldo', -5);
        $this->assertDatabaseCount('egg_stock_transactions', 2);
    }

    /** Flujo: impide que el inventario genérico modifique el producto o ubicación técnicos. */
    public function test_generic_inventory_cannot_move_egg_account(): void
    {
        // Preparación: crea una cuenta técnica mediante un ingreso especializado.
        $this->signIn(['egg-stock.view', 'egg-stock.move', 'inventory.move']);
        $unit = ProductionUnit::factory()->create();
        $this->command('POST', "/unidades-productivas/{$unit->id}/stock-huevos/ingresos", ['cantidad' => 2, 'motivo' => 'Carga'])->assertCreated();
        $product = Product::query()->where('system_key', 'generic_egg')->firstOrFail();
        $location = StockLocation::query()->where('system_managed', true)->where('production_unit_id', $unit->id)->firstOrFail();
        $supplier = Supplier::factory()->create();

        // Request: intenta utilizar el endpoint genérico con la cuenta protegida.
        $this->command('POST', '/inventario/ingresos', ['proveedor_id' => $supplier->id, 'lineas' => [['producto_id' => $product->id, 'ubicacion_stock_id' => $location->id, 'cantidad' => '1']], 'motivo' => 'Acceso directo'])->assertStatus(409);
    }

    /** Flujo: corrige cantidad, texto y fecha sin perder el saldo ni el histórico. */
    public function test_manual_correction_preserves_date_when_omitted_and_rebases_when_explicit(): void
    {
        // Preparación: registra un ingreso manual con una fecha efectiva conocida.
        $this->signIn(['egg-stock.view', 'egg-stock.move', 'egg-stock.adjust']);
        $unit = ProductionUnit::factory()->create();
        $created = $this->command('POST', "/unidades-productivas/{$unit->id}/stock-huevos/ingresos", [
            'cantidad' => 10,
            'ocurrido_en' => '2026-08-01T10:00:00-03:00',
            'motivo' => 'Carga manual',
        ])->assertCreated();
        $transactionId = $created->json('data.transaction');

        // Acción: corrige la cantidad manteniendo la fecha efectiva anterior.
        $this->command('PATCH', "/stock-huevos/movimientos/{$transactionId}", [
            'version' => 1,
            'cantidad' => 4,
            'motivo_correccion' => 'Error de digitación',
        ])->assertOk();

        // Acción: corrige sólo el texto, sin generar un movimiento de cantidad cero.
        $this->command('PATCH', "/stock-huevos/movimientos/{$transactionId}", [
            'version' => 2,
            'observaciones' => 'Revisado',
            'motivo_correccion' => 'Aclaración',
        ])->assertOk();

        // Acción: mueve explícitamente la fecha y compensa el efecto histórico.
        $this->command('PATCH', "/stock-huevos/movimientos/{$transactionId}", [
            'version' => 3,
            'ocurrido_en' => '2026-08-02T10:00:00-03:00',
            'motivo_correccion' => 'Fecha corregida',
        ])->assertOk();

        // Verificación: el saldo refleja la última cantidad y las revisiones son append-only.
        $this->getJson("/api/v1/unidades-productivas/{$unit->id}/stock-huevos")->assertOk()->assertJsonPath('data.saldo', 4);
        $this->assertDatabaseCount('egg_stock_transaction_revisions', 3);
        $this->assertDatabaseCount('inventory_movements', 4);
        $this->assertDatabaseHas('egg_stock_transactions', ['public_id' => $transactionId, 'quantity' => 4, 'version' => 4]);
    }
}
