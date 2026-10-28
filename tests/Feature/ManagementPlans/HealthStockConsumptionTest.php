<?php

namespace Tests\Feature\ManagementPlans;

use App\Actions\Inventory\RecordInventoryMovementAction;
use App\DTO\Inventory\InventoryMovementCommand;
use App\Enums\Inventory\InventoryMovementType;
use App\Exceptions\Inventory\InventoryConflict;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Medicine;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Vaccine;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Feature\Lots\LotsTestCase;

final class HealthStockConsumptionTest extends LotsTestCase
{
    // Flujo: descuenta stock físico opcional, conserva el contador clínico y compensa ambos sin borrar historia.
    #[TestWith(['medication', 'medicaciones', 'medicine_id'])]
    #[TestWith(['vaccination', 'vacunaciones', 'vaccine_id'])]
    public function test_health_consumption_is_atomic_idempotent_and_compensated(string $type, string $path, string $field): void
    {
        // Futuro: ejercita explícitamente la integración conservada, deshabilitada en la configuración real.
        config()->set('inventory.health_stock_enabled', true);
        // Preparación: usa sólo permiso de ejecución y stock sanitario de la UP del lote.
        $actor = $this->signIn(['management-plans.execute']);
        $flock = $this->flockWithPlan();
        $catalog = $type === 'medication'
            ? Medicine::factory()->create(['product_id' => Product::factory()->create(['kind' => 'medicine', 'base_unit' => 'unit'])->id])
            : Vaccine::factory()->create();
        $location = StockLocation::factory()->create(['type' => 'health', 'production_unit_id' => $flock->production_unit_id, 'system_managed' => true]);
        $balance = StockBalance::factory()->create(['product_id' => $catalog->product_id, 'stock_location_id' => $location->id, 'on_hand_quantity' => '5']);
        $payload = [$field => $catalog->public_id, 'quantity' => 2, 'reason' => 'Aplicación sanitaria', 'occurred_at' => now()->subMinute()->toIso8601String(),
            'stock_consumption' => ['quantity' => '6', 'stock_location_id' => $location->id]];
        if ($type === 'vaccination') {
            unset($payload['quantity'], $payload['reason']);
        }
        $url = '/flocks/'.$flock->public_id.'/'.$path;

        // Request: el stock insuficiente revierte también aplicación, contador clínico y auditoría.
        $this->command('POST', $url, $payload)->assertConflict();
        $this->assertDatabaseCount($type === 'medication' ? 'medicine_applications' : 'vaccination_applications', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame('5.000000', (string) $balance->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('medicine_stock_movements', 0);

        // Request: un consumo válido y su repetición sólo descuentan una vez.
        $payload['stock_consumption']['quantity'] = '3';
        $key = (string) Str::uuid();
        $first = $this->command('POST', $url, $payload, $key)->assertCreated();
        $this->command('POST', $url, $payload, $key)->assertCreated()->assertJsonPath('data.operation_id', $first->json('data.operation_id'));
        $this->assertSame('2.000000', (string) $balance->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_movements', 1);
        $applicationId = $first->json('data.'.($type === 'medication' ? 'medicine_application' : 'vaccination_application').'.id');

        // Request: la corrección compensa el consumo físico y deja la aplicación original consultable.
        config()->set('inventory.health_stock_enabled', false);
        $catalog->product->forceFill(['stock_tracked' => false])->save();
        $original = InventoryMovement::query()->sole();

        // Mutación: la excepción histórica no permite devolver más cantidad que la consumida.
        try {
            app(RecordInventoryMovementAction::class)->execute(new InventoryMovementCommand(
                type: InventoryMovementType::Reversal,
                lines: [['product_id' => (int) $catalog->product_id, 'stock_location_id' => $location->id, 'on_hand_delta' => '4']],
                operationId: (string) Str::uuid(), referenceType: 'inventory_movement', referenceId: (string) $original->id,
                reversesMovementId: $original->id,
            ), $actor, requiredPermission: 'management-plans.execute', healthCompensation: $original);
            $this->fail('La compensación debía limitarse al consumo original.');
        } catch (InventoryConflict $exception) {
            $this->assertSame('Debes devolver exactamente el consumo sanitario original.', $exception->getMessage());
            $this->assertSame('2.000000', (string) $balance->fresh()->on_hand_quantity);
            $this->assertDatabaseCount('inventory_movements', 1);
        }

        // Request: la corrección real sigue disponible con sanidad deshabilitada y es idempotente.
        $correctionUrl = '/flocks/'.$flock->public_id.'/manejos/'.$type.'/'.$applicationId.'/correcciones';
        $correctionKey = (string) Str::uuid();
        $this->command('POST', $correctionUrl, ['reason' => 'Registro equivocado'], $correctionKey)->assertCreated();
        $this->command('POST', $correctionUrl, ['reason' => 'Registro equivocado'], $correctionKey)->assertCreated();
        $this->assertSame('5.000000', (string) $balance->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertDatabaseCount($type === 'medication' ? 'medicine_applications' : 'vaccination_applications', 1);
        $this->assertDatabaseCount('management_execution_corrections', 1);
    }

    // Flujo: impide consumir sanidad de otra UP aunque el usuario tenga acceso global a ambas.
    #[TestWith(['medicine'])]
    #[TestWith(['vaccine'])]
    public function test_consumption_rejects_another_production_unit_without_changing_balances(string $kind): void
    {
        // Futuro: mantiene cubierta la restricción por UP sin habilitarla en la aplicación.
        config()->set('inventory.health_stock_enabled', true);
        // Preparación: la ubicación correcta para el producto pertenece a otra UP.
        $this->signIn(['management-plans.execute']);
        $flock = $this->flockWithPlan();
        $product = Product::factory()->create(['kind' => $kind, 'base_unit' => 'unit']);
        $catalog = $kind === 'medicine' ? Medicine::factory()->create(['product_id' => $product->id]) : Vaccine::factory()->create(['product_id' => $product->id]);
        $otherUnit = ProductionUnit::factory()->create();
        $location = StockLocation::factory()->create(['type' => 'health', 'production_unit_id' => $otherUnit->id]);
        $balance = StockBalance::factory()->create(['product_id' => $product->id, 'stock_location_id' => $location->id, 'on_hand_quantity' => '10']);
        $payload = [$kind.'_id' => $catalog->public_id, 'occurred_at' => now()->subMinute()->toIso8601String(), 'stock_consumption' => ['quantity' => '1', 'stock_location_id' => $location->id]];
        if ($kind === 'medicine') {
            $payload = [...$payload, 'quantity' => 1, 'reason' => 'Tratamiento'];
        }

        // Request: el contexto de la aplicación determina la UP del consumo.
        $this->command('POST', '/flocks/'.$flock->public_id.'/'.($kind === 'medicine' ? 'medicaciones' : 'vacunaciones'), $payload)->assertConflict();
        $this->assertSame('10.000000', (string) $balance->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('medicine_stock_movements', 0);
    }

    // Flujo: el alcance actual admite aplicaciones globales y rechaza cualquier consumo físico.
    #[TestWith(['medicine', 'medicaciones'])]
    #[TestWith(['vaccine', 'vacunaciones'])]
    public function test_disabled_physical_consumption_returns_422_but_global_application_succeeds(string $kind, string $path): void
    {
        // Preparación: usa el catálogo global sin saldos ni ubicaciones sanitarias.
        $this->signIn(['management-plans.execute']);
        $flock = $this->flockWithPlan();
        $catalog = $kind === 'medicine' ? Medicine::factory()->create()
            : Vaccine::factory()->create(['product_id' => Product::factory()->vaccine()->create(['stock_tracked' => false])->id]);
        $payload = [$kind.'_id' => $catalog->public_id, 'occurred_at' => now()->subMinute()->toIso8601String(),
            'stock_consumption' => ['quantity' => '1', 'stock_location_id' => 999]];
        if ($kind === 'medicine') {
            $payload = [...$payload, 'quantity' => 1, 'reason' => 'Registro clínico global'];
        }
        $url = '/flocks/'.$flock->public_id.'/'.$path;

        // Request: no ignora silenciosamente una intención de descontar inventario físico.
        $this->command('POST', $url, $payload)->assertUnprocessable()->assertJsonValidationErrors('stock_consumption')
            ->assertJsonPath('errors.stock_consumption.0', 'El stock físico de sanidad está deshabilitado. Registra la aplicación sin consumo de inventario.');
        $this->assertDatabaseCount($kind === 'medicine' ? 'medicine_applications' : 'vaccination_applications', 0);

        // Request: la aplicación global sigue disponible sin vínculo de inventario ni existencia física.
        unset($payload['stock_consumption']);
        $this->command('POST', $url, $payload)->assertCreated();
        $this->assertDatabaseCount($kind === 'medicine' ? 'medicine_applications' : 'vaccination_applications', 1);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('stock_balances', 0);
        $this->assertSame(0, StockLocation::query()->where('type', 'health')->count());
    }
}
