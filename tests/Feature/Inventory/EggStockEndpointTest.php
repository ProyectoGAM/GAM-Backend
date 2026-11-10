<?php

namespace Tests\Feature\Inventory;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\StockLocation;
use App\Models\Notifications\AppNotification;
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
            $user->roles()->firstOrFail()->givePermissionTo(Permission::findOrCreate($permission, 'web'));
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
        $this->signIn(['can_view_egg_stock', 'can_move_egg_stock', 'can_adjust_egg_stock']);
        $unit = ProductionUnit::factory()->create();

        // Requests: registra ingreso manual y dos egresos especializados.
        $this->command('POST', "/production-units/{$unit->id}/egg-stock/receipts", ['quantity' => 10, 'reason' => 'Carga manual'])->assertCreated();
        $this->command('POST', "/production-units/{$unit->id}/egg-stock/issues", ['quantity' => 15, 'type' => 'loss', 'reason' => 'Rotura'])->assertCreated();

        // Consulta: confirma que las cuentas de huevo permiten saldo negativo.
        $this->getJson("/api/v1/production-units/{$unit->id}/egg-stock")->assertOk()->assertJsonPath('data.balance', -5);
        $this->assertDatabaseCount('egg_stock_transactions', 2);
    }

    // Flujo: mantiene un aviso por UP mientras el saldo sea negativo y abre otro episodio tras recuperarlo.
    public function test_negative_stock_alert_tracks_the_committed_balance(): void
    {
        // Preparación: registra una cuenta con diez huevos y permiso para ver su stock en la bandeja.
        $actor = $this->signIn(['can_view_egg_stock', 'can_move_egg_stock', 'can_adjust_egg_stock', 'can_view_notifications']);
        $unit = ProductionUnit::factory()->create();
        $path = "/production-units/{$unit->id}/egg-stock";
        $this->command('POST', $path.'/receipts', ['quantity' => 10, 'reason' => 'Ingreso'])->assertCreated();
        $this->assertDatabaseCount('app_notifications', 0);

        // Requests: dos pérdidas actualizan el mismo aviso alto con el saldo de la UP.
        $issue = $this->command('POST', $path.'/issues', [
            'quantity' => 15, 'type' => 'loss', 'reason' => 'Rotura', 'occurred_at' => '2026-08-01T10:00:00-03:00',
        ])->assertCreated()->json('data.transaction');
        $notice = AppNotification::query()->sole();
        $this->command('POST', $path.'/issues', ['quantity' => 2, 'type' => 'loss', 'reason' => 'Rotura adicional'])->assertCreated();
        $noticeUrl = '/api/v1/notificaciones/'.$notice->public_id;
        $this->assertSame($actor->id, $notice->recipient_id);
        $this->assertStringContainsString('-7', $notice->refresh()->message);
        $this->getJson($noticeUrl)->assertOk()
            ->assertJsonPath('data.tipo', 'egg_stock_negative')
            ->assertJsonPath('data.urgencia', 'alto')
            ->assertJsonPath('data.entidad.id', (string) $unit->id)
            ->assertJsonPath('data.entidad.ruta', '/administracion/inventario/existencias/huevos');

        // Request: cambiar la fecha compensa y vuelve a aplicar el egreso sin cerrar ni duplicar el aviso.
        $this->command('PATCH', "/egg-stock/movements/{$issue}", [
            'version' => 1, 'occurred_at' => '2026-08-02T10:00:00-03:00', 'correction_reason' => 'Fecha corregida',
        ])->assertOk();
        $this->assertNull($notice->refresh()->resolved_at);
        $this->assertDatabaseCount('app_notifications', 1);

        // Requests: llegar a cero resuelve el aviso; cancelar ese ingreso abre un episodio nuevo.
        $receipt = $this->command('POST', $path.'/receipts', ['quantity' => 7, 'reason' => 'Reposición'])->assertCreated()->json('data.transaction');
        $this->getJson($noticeUrl)->assertOk()->assertJsonPath('data.estado_resolucion', 'resuelto');
        $this->command('POST', "/egg-stock/movements/{$receipt}/cancellation", [
            'version' => 1, 'correction_reason' => 'Ingreso duplicado',
        ])->assertOk();
        $reopened = AppNotification::query()->whereNull('resolved_at')->sole();
        $this->assertNotSame($notice->id, $reopened->id);
        $this->assertStringContainsString('-7', $reopened->message);
    }

    // Flujo: recupera un saldo negativo existente y archiva los avisos anteriores de carga.
    public function test_scheduled_evaluation_reconciles_existing_negative_stock_and_legacy_notices(): void
    {
        // Preparación: deja una UP en negativo antes de habilitar el acceso a su stock.
        $actor = $this->signIn(['can_move_egg_stock', 'can_view_notifications']);
        $unit = ProductionUnit::factory()->create();
        $this->command('POST', "/production-units/{$unit->id}/egg-stock/issues", [
            'quantity' => 4, 'type' => 'loss', 'reason' => 'Rotura',
        ])->assertCreated();
        $this->assertDatabaseCount('app_notifications', 0);
        $legacy = AppNotification::factory()->create([
            'recipient_id' => $actor->id, 'production_unit_id' => $unit->id,
            'module' => 'deliveries', 'type' => 'delivery_egg_stock_negative', 'urgency' => 'alto', 'is_alert' => true,
        ]);
        $actor->roles()->firstOrFail()->givePermissionTo(Permission::findOrCreate('can_view_egg_stock', 'web'));

        // Consulta: el comando reemplaza el pendiente anterior y repetirlo no duplica el nuevo.
        $this->artisan('inventario:evaluar-stock-huevos')->assertSuccessful();
        $this->artisan('inventario:evaluar-stock-huevos')->assertSuccessful();
        $notice = AppNotification::query()->whereNull('resolved_at')->sole();
        $this->assertSame('egg_stock_negative', $notice->type);
        $this->assertSame($actor->id, $notice->recipient_id);
        $this->assertNotNull($legacy->refresh()->resolved_at);
        $this->assertDatabaseCount('app_notifications', 2);
    }

    /** Flujo: impide que el inventario genérico modifique el producto o ubicación técnicos. */
    public function test_generic_inventory_cannot_move_egg_account(): void
    {
        // Preparación: crea una cuenta técnica mediante un ingreso especializado.
        $this->signIn(['can_view_egg_stock', 'can_move_egg_stock', 'can_move_inventory']);
        $unit = ProductionUnit::factory()->create();
        $this->command('POST', "/production-units/{$unit->id}/egg-stock/receipts", ['quantity' => 2, 'reason' => 'Carga'])->assertCreated();
        $product = Product::query()->where('system_key', 'generic_egg')->firstOrFail();
        $location = StockLocation::query()->where('system_managed', true)->where('production_unit_id', $unit->id)->firstOrFail();
        $supplier = Supplier::factory()->create();

        // Request: intenta utilizar el endpoint genérico con la cuenta protegida.
        $this->command('POST', '/inventory/receipts', ['supplier_id' => $supplier->id, 'lines' => [['product_id' => $product->id, 'stock_location_id' => $location->id, 'quantity' => '1']], 'reason' => 'Acceso directo'])->assertStatus(409);
    }

    /** Flujo: corrige cantidad, texto y fecha sin perder el saldo ni el histórico. */
    public function test_manual_correction_preserves_date_when_omitted_and_rebases_when_explicit(): void
    {
        // Preparación: registra un ingreso manual con una fecha efectiva conocida.
        $this->signIn(['can_view_egg_stock', 'can_move_egg_stock', 'can_adjust_egg_stock']);
        $unit = ProductionUnit::factory()->create();
        $created = $this->command('POST', "/production-units/{$unit->id}/egg-stock/receipts", [
            'quantity' => 10,
            'occurred_at' => '2026-08-01T10:00:00-03:00',
            'reason' => 'Carga manual',
        ])->assertCreated();
        $transactionId = $created->json('data.transaction');

        // Acción: corrige la cantidad manteniendo la fecha efectiva anterior.
        $this->command('PATCH', "/egg-stock/movements/{$transactionId}", [
            'version' => 1,
            'quantity' => 4,
            'correction_reason' => 'Error de digitación',
        ])->assertOk();

        // Acción: corrige sólo el texto, sin generar un movimiento de cantidad cero.
        $this->command('PATCH', "/egg-stock/movements/{$transactionId}", [
            'version' => 2,
            'notes' => 'Revisado',
            'correction_reason' => 'Aclaración',
        ])->assertOk();

        // Acción: mueve explícitamente la fecha y compensa el efecto histórico.
        $this->command('PATCH', "/egg-stock/movements/{$transactionId}", [
            'version' => 3,
            'occurred_at' => '2026-08-02T10:00:00-03:00',
            'correction_reason' => 'Fecha corregida',
        ])->assertOk();

        // Verificación: el saldo refleja la última cantidad y las revisiones son append-only.
        $this->getJson("/api/v1/production-units/{$unit->id}/egg-stock")->assertOk()->assertJsonPath('data.balance', 4);
        $this->assertDatabaseCount('egg_stock_transaction_revisions', 3);
        $this->assertDatabaseCount('inventory_movements', 4);
        $this->assertDatabaseHas('egg_stock_transactions', ['public_id' => $transactionId, 'quantity' => 4, 'version' => 4]);
    }
}
