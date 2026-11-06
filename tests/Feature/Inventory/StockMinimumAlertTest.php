<?php

namespace Tests\Feature\Inventory;

use App\Enums\Inventory\StockLocationStatus;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\Notifications\AppNotification;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class StockMinimumAlertTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: el aviso escala al quedar por debajo del mínimo y se resuelve al reponer el stock.
    public function test_stock_alert_escalates_below_minimum_and_resolves_after_restock(): void
    {
        // Preparación: crea un saldo decimal suficiente en una ubicación general.
        $actor = User::factory()->create();
        foreach (['can_view_inventory', 'can_view_notifications', 'can_move_inventory', 'can_manage_inventory'] as $permission) {
            $actor->roles()->firstOrFail()->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Sanctum::actingAs($actor, ['*']);
        $balance = StockBalance::factory()->create(['on_hand_quantity' => '10.500001', 'minimum_quantity' => '10.500000']);
        $supplier = Supplier::factory()->create();
        $line = ['product_id' => $balance->product_id, 'stock_location_id' => $balance->stock_location_id];

        // Consulta: una millonésima por encima del mínimo deja la bandeja vacía.
        $this->artisan('inventario:evaluar-minimos')->assertSuccessful();
        $this->assertDatabaseCount('app_notifications', 0);

        // Request y consulta: subir el mínimo hasta el saldo abre un único aviso medio.
        $this->patchJson('/api/v1/inventory/balances/'.$balance->id.'/minimum-stock', [
            'minimum_quantity' => '10.500001',
        ])->assertOk();
        $this->artisan('inventario:evaluar-minimos')->assertSuccessful();
        $this->artisan('inventario:evaluar-minimos')->assertSuccessful();
        $notice = AppNotification::query()->sole();
        $noticeUrl = '/api/v1/notificaciones/'.$notice->public_id;
        $this->getJson($noticeUrl)->assertOk()
            ->assertJsonPath('data.urgencia', 'medio')
            ->assertJsonPath('data.tipo', 'stock_minimum_reached')
            ->assertJsonPath('data.modulo', 'inventory')
            ->assertJsonPath('data.entidad.id', (string) $balance->id)
            ->assertJsonPath('data.entidad.ruta', '/administracion/inventario/existencias');

        // Request y consulta: al retirar una millonésima, el mismo aviso pasa a alto y no leído.
        $this->patchJson($noticeUrl.'/lectura')->assertOk();
        $this->postJson('/api/v1/inventory/issues', [
            'lines' => [[...$line, 'quantity' => '0.000001']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->artisan('inventario:evaluar-minimos')->assertSuccessful();
        $this->getJson($noticeUrl)->assertOk()
            ->assertJsonPath('data.urgencia', 'alto')
            ->assertJsonPath('data.leida_en', null);

        // Request y consulta: al reponer por encima del mínimo, el aviso queda en el historial.
        $this->postJson('/api/v1/inventory/receipts', [
            'supplier_id' => $supplier->id,
            'lines' => [[...$line, 'quantity' => '1']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->artisan('inventario:evaluar-minimos')->assertSuccessful();
        $this->getJson($noticeUrl)->assertOk()->assertJsonPath('data.estado_resolucion', 'resuelto');
        $this->assertDatabaseCount('app_notifications', 1);
    }

    // Flujo: las alertas distinguen saldos controlados por ubicación y respetan la UP del destinatario.
    public function test_only_eligible_stock_alerts_reach_recipients_in_scope(): void
    {
        // Preparación: crea dos ubicaciones con el mismo producto y saldos independientes.
        config(['identity.pin.pepper' => 'stock-minimum-alert-test']);
        $unit = ProductionUnit::factory()->create();
        $location = StockLocation::factory()->create(['production_unit_id' => $unit->id]);
        $globalLocation = StockLocation::factory()->create();
        $product = Product::factory()->create();
        $localBalance = StockBalance::factory()->for($location, 'stockLocation')->for($product)->create();
        $globalBalance = StockBalance::factory()->for($globalLocation, 'stockLocation')->for($product)->create();
        StockBalance::factory()->for($location, 'stockLocation')->for(Product::factory()->state(['stock_tracked' => false]))->create();
        StockBalance::factory()->for(StockLocation::factory()->state(['status' => StockLocationStatus::Inactive]), 'stockLocation')->create();

        // Preparación: asigna permisos a una cuenta personal y a dos cuentas PIN de UP distintas.
        $personal = User::factory()->create();
        $local = User::factory()->devicePin($unit)->create();
        $external = User::factory()->devicePin()->create();
        $read = Permission::findOrCreate('can_view_inventory', 'web');
        $inbox = Permission::findOrCreate('can_view_notifications', 'web');
        foreach ([$personal, $local, $external] as $recipient) {
            $recipient->roles()->firstOrFail()->givePermissionTo($read, $inbox);
        }

        // Consulta: el mínimo cero cuenta y la cuenta PIN recibe sólo el aviso de su ubicación.
        $this->artisan('inventario:evaluar-minimos')->assertSuccessful();
        $this->assertDatabaseCount('app_notifications', 3);
        $this->assertDatabaseHas('app_notifications', [
            'recipient_id' => $personal->id, 'production_unit_id' => null,
            'entity_id' => (string) $globalBalance->id, 'urgency' => 'medio',
        ]);
        foreach ([$personal, $local] as $recipient) {
            $this->assertDatabaseHas('app_notifications', [
                'recipient_id' => $recipient->id, 'production_unit_id' => $unit->id,
                'entity_id' => (string) $localBalance->id, 'urgency' => 'medio',
            ]);
        }
    }
}
