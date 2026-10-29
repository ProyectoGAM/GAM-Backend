<?php

namespace Tests\Feature\Inventory;

use App\Actions\FarmStructure\CreateProductionUnitAction;
use App\Actions\Inventory\EnsureEggStockAccountAction;
use App\Actions\Inventory\EnsureHealthStockLocationAction;
use App\Actions\Inventory\PrepareStockStorageAction;
use App\Actions\Inventory\RecordInventoryMovementAction;
use App\Actions\SuppliersAndCatalogs\ConfigureInternalSupplierAction;
use App\DTO\Inventory\InventoryMovementCommand;
use App\Enums\Inventory\InventoryMovementType;
use App\Enums\Inventory\StockLocationType;
use App\Exceptions\Inventory\InventoryConflict;
use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Medicine;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\SuppliersAndCatalogs\Vaccine;
use App\Models\User;
use Database\Seeders\Inventory\HealthStockDemoSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class StockStorageEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: verifica todas las categorías mediante la consulta pública de cada tipo explícito.
    #[DataProvider('locationKinds')]
    public function test_selector_uses_explicit_storage_type(string $type, array $expectedKinds): void
    {
        // Preparación: usa nombres arbitrarios y una UP incluso para la cámara de insumos.
        $actor = $this->admin();
        $location = $this->location($type, $actor);
        foreach (['raw_material', 'supply', 'other', 'finished_feed', 'egg', 'medicine', 'vaccine'] as $kind) {
            Product::factory()->create(['kind' => $kind, 'base_unit' => 'g', 'name' => $kind]);
        }

        // Consulta: la matriz es igual para las cinco operaciones y conserva la paginación.
        foreach (['receipt', 'issue', 'adjustment', 'transfer', 'loss'] as $operation) {
            if ($operation === 'loss') {
                foreach (Product::query()->whereNull('system_key')->get() as $product) {
                    StockBalance::factory()->create(['product_id' => $product->id, 'stock_location_id' => $location->id, 'on_hand_quantity' => '1']);
                }
            }
            $response = $this->getJson('/api/v1/inventario/productos-permitidos?'.http_build_query(['stock_location_id' => $location->id, 'operation' => $operation]))->assertOk();
            $this->assertSame($expectedKinds, array_column($response->json('data'), 'kind'));
        }
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public static function locationKinds(): array
    {
        return [
            'cámara con UP' => ['warehouse', ['other', 'supply']],
            'sanidad reservada' => ['health', []],
            'planta' => ['feed_plant', ['raw_material']],
            'cuenta real' => ['egg_account', ['egg']],
            'gestionada desconocida' => ['unknown', []],
        ];
    }

    // Flujo: impide eludir la matriz enviando directamente cualquiera de los cinco comandos HTTP.
    #[DataProvider('invalidMovements')]
    public function test_incompatible_writes_return_409_without_partial_changes(string $operation, string $type, string $kind): void
    {
        // Preparación: incluso el saldo histórico positivo debe permanecer intacto tras el rechazo.
        $actor = $this->admin();
        $location = $this->location($type, $actor);
        $product = Product::factory()->create(['kind' => $kind, 'base_unit' => 'g']);
        $balance = StockBalance::factory()->create(['product_id' => $product->id, 'stock_location_id' => $location->id, 'on_hand_quantity' => '5']);
        $destination = StockLocation::factory()->create();
        $auditCount = AuditEntry::query()->count();

        // Request: cada entrada atraviesa la misma validación transaccional.
        $payload = $this->payload($operation, $product, $location, $destination);
        $this->postJson('/api/v1/inventory/'.$operation, $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->assertSame('5.000000', (string) $balance->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('stock_balances', 1);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('activity_log', $auditCount);
    }

    public static function invalidMovements(): array
    {
        $cases = [];
        foreach (['receipts', 'issues', 'losses', 'adjustments', 'transfers'] as $operation) {
            foreach ([['warehouse', 'medicine'], ['warehouse', 'vaccine'], ['warehouse', 'egg'], ['warehouse', 'finished_feed'], ['warehouse', 'raw_material'], ['health', 'supply'], ['feed_plant', 'vaccine'], ['unknown', 'supply']] as [$type, $kind]) {
                $cases[$operation.' '.$type.' '.$kind] = [$operation, $type, $kind];
            }
        }

        return $cases;
    }

    // Flujo: resuelve Propio por identidad estable y conserva el resultado de los reintentos.
    public function test_omitted_supplier_is_resolved_without_creation_and_replayed_after_deactivation(): void
    {
        // Preparación: configura una vez, cambia el nombre editable y registra un ingreso sin proveedor.
        $actor = $this->admin();
        $supplier = app(ConfigureInternalSupplierAction::class)->execute($actor);
        $supplier->forceFill(['name' => 'Producción interna'])->save();
        $product = Product::factory()->create();
        $location = StockLocation::factory()->create();
        $payload = $this->payload('receipts', $product, $location);
        unset($payload['supplier_id']);
        $key = (string) Str::uuid();
        $first = $this->postJson('/api/v1/inventory/receipts', $payload, ['Idempotency-Key' => $key])->assertCreated()
            ->assertJsonPath('data.supplier.id', $supplier->id)->assertJsonPath('data.supplier.is_internal', true);

        // Mutación: el estado posterior no debe invalidar una operación ya confirmada.
        $supplier->forceFill(['status' => 'inactive'])->save();
        $this->postJson('/api/v1/inventory/receipts', $payload, ['Idempotency-Key' => $key])->assertCreated()
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson('/api/v1/inventory/receipts', $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('suppliers', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    // Flujo: no inventa un proveedor y respeta siempre una selección explícita activa.
    public function test_missing_internal_supplier_blocks_omission_but_not_an_explicit_supplier(): void
    {
        // Preparación: deja un proveedor llamado Propio sin configurar su identidad interna.
        $this->admin();
        $namedOnly = Supplier::factory()->create(['name' => 'Propio']);
        $product = Product::factory()->create();
        $location = StockLocation::factory()->create();
        $payload = $this->payload('receipts', $product, $location);
        unset($payload['supplier_id']);

        // Request: el nombre no configura proveedores como efecto secundario del ingreso.
        $this->postJson('/api/v1/inventory/receipts', $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->postJson('/api/v1/inventory/receipts', [...$payload, 'supplier_id' => $namedOnly->id], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->assertNull($namedOnly->fresh()->system_key);
    }

    // Flujo: clasifica por relaciones reales sin activar sanidad ni vincular medicamentos al inventario.
    public function test_preparation_is_idempotent_audited_and_preserves_historical_balances(): void
    {
        // Preparación: crea ubicaciones previas, una ficha sin vínculo y un saldo incompatible.
        $actor = $this->admin();
        $unit = ProductionUnit::factory()->create();
        $warehouse = StockLocation::factory()->create(['type' => 'unknown', 'production_unit_id' => $unit->id]);
        $orphan = StockLocation::factory()->create(['type' => 'unknown', 'system_managed' => true, 'production_unit_id' => $unit->id]);
        $medicine = Medicine::factory()->create();
        $balance = StockBalance::factory()->create(['stock_location_id' => $warehouse->id, 'on_hand_quantity' => '8']);
        $before = $balance->fresh()->getAttributes();

        // Mutación: repetir la preparación no duplica ubicaciones, vínculos, proveedor ni auditoría.
        app(PrepareStockStorageAction::class)->execute($actor);
        $auditCount = AuditEntry::query()->count();
        app(PrepareStockStorageAction::class)->execute($actor);
        $this->assertSame($auditCount, AuditEntry::query()->count());
        $this->assertSame($before, $balance->fresh()->getAttributes());
        $this->assertSame(StockLocationType::Warehouse, $warehouse->fresh()->type);
        $this->assertSame(StockLocationType::Unknown, $orphan->fresh()->type);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame(0, StockLocation::query()->where('type', 'health')->where('production_unit_id', $unit->id)->count());
        $this->assertNull($medicine->fresh()->product_id);
    }

    // Flujo: regulariza un saldo incompatible mediante traslado compensatorio e idempotente.
    public function test_regularization_moves_only_historical_incompatible_stock_and_preserves_history(): void
    {
        // Preparación: la materia prima antigua está fuera de una planta y conserva su historial original.
        $actor = $this->admin();
        $product = Product::factory()->create(['kind' => 'raw_material', 'base_unit' => 'g']);
        $warehouse = StockLocation::factory()->create();
        $destination = $this->location('feed_plant', $actor);
        $balance = StockBalance::factory()->create(['product_id' => $product->id, 'stock_location_id' => $warehouse->id, 'on_hand_quantity' => '8']);
        $key = (string) Str::uuid();
        $payload = ['mode' => 'trasladar', 'quantity' => '8', 'to_stock_location_id' => $destination->id, 'reason' => 'Regularización revisada del depósito antiguo'];
        $url = '/api/v1/inventario/saldos/'.$balance->id.'/regularizaciones';

        // Consulta: la revisión muestra el saldo incompatible sin cambiarlo.
        $this->getJson('/api/v1/inventory/balances?incompatible_only=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $balance->id);

        // Request: el reintento devuelve el mismo movimiento y la auditoría conserva antes y después.
        $first = $this->postJson($url, $payload, ['Idempotency-Key' => $key])->assertCreated();
        $this->postJson($url, $payload, ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseHas('stock_balances', ['id' => $balance->id, 'on_hand_quantity' => '0']);
        $this->assertDatabaseHas('stock_balances', ['product_id' => $product->id, 'stock_location_id' => $destination->id, 'on_hand_quantity' => '8']);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->getJson('/api/v1/inventory/balances?incompatible_only=1')->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson('/api/v1/inventory/movements/'.$first->json('data.id').'/reversals', ['reason' => 'No reintroducir saldo inválido'], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
    }

    // Flujo: permite cerrar un saldo histórico negativo sin crear stock ni borrar historia.
    public function test_regularization_count_clears_negative_incompatible_stock(): void
    {
        // Preparación: representa una deuda antigua de vacuna en una cámara.
        $this->admin();
        $product = Product::factory()->create(['kind' => 'vaccine', 'base_unit' => 'dose']);
        $balance = StockBalance::factory()->create(['product_id' => $product->id, 'on_hand_quantity' => '-3', 'allow_negative' => true]);

        // Request: sólo admite el cierre exacto a cero con motivo obligatorio.
        $this->postJson('/api/v1/inventario/saldos/'.$balance->id.'/regularizaciones', ['mode' => 'recontar', 'reason' => 'Recuento validado sin existencias'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame('0.000000', (string) $balance->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_movement_lines', 1);
    }

    // Flujo: exige sesión, permisos funcionales y parámetros acotados para regularizar.
    public function test_regularization_authentication_authorization_and_validation(): void
    {
        // Preparación: crea un saldo accesible sin asociaciones por usuario y UP.
        $balance = StockBalance::factory()->create();
        $url = '/api/v1/inventario/saldos/'.$balance->id.'/regularizaciones';

        // Request: comprueba cada frontera antes de intentar modificar stock.
        $this->postJson($url, [])->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->postJson($url, [])->assertForbidden();
        $this->admin();
        $this->postJson($url, [], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['mode', 'reason']);
        $this->postJson($url, ['mode' => 'recontar', 'reason' => 'No es incompatible'], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    // Flujo: rechaza las cinco operaciones físicas sobre medicamentos mientras sanidad está desactivada.
    #[DataProvider('validHealthOperations')]
    public function test_disabled_health_storage_rejects_generic_movements(string $operation, string $kind): void
    {
        // Preparación: inicia stock físico suficiente y dos ubicaciones sanitarias.
        $actor = $this->admin();
        $location = $this->location('health', $actor);
        $destination = $this->location('health', $actor);
        $product = Product::factory()->create(['kind' => $kind, 'base_unit' => 'unit']);
        StockBalance::factory()->create(['product_id' => $product->id, 'stock_location_id' => $location->id, 'on_hand_quantity' => '5']);

        // Request: el comando no puede utilizar ubicaciones sanitarias históricas aunque tengan saldo.
        $payload = $this->payload($operation, $product, $location, $destination);
        $this->postJson('/api/v1/inventory/'.$operation, $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseHas('stock_balances', ['product_id' => $product->id, 'stock_location_id' => $location->id, 'on_hand_quantity' => '5']);
    }

    public static function validHealthOperations(): array
    {
        $cases = [];
        foreach (['medicine', 'vaccine'] as $kind) {
            foreach (['receipts', 'issues', 'losses', 'adjustments', 'transfers'] as $operation) {
                $cases[$kind.' '.$operation] = [$operation, $kind];
            }
        }

        return $cases;
    }

    // Flujo: valida incluso un extremo intermedio cuyo delta neto se cancelaría.
    public function test_transfer_cannot_hide_an_incompatible_intermediate_location(): void
    {
        // Preparación: conecta dos ubicaciones sanitarias pasando por una cámara incompatible.
        $actor = $this->admin();
        $origin = $this->location('health', $actor);
        $destination = $this->location('health', $actor);
        $intermediate = StockLocation::factory()->create();
        $product = Product::factory()->create(['kind' => 'medicine', 'base_unit' => 'unit']);
        StockBalance::factory()->create(['product_id' => $product->id, 'stock_location_id' => $origin->id, 'on_hand_quantity' => '5']);

        // Request: se rechaza la ubicación intermedia antes de combinar las líneas.
        $this->postJson('/api/v1/inventory/transfers', ['lines' => [
            ['product_id' => $product->id, 'from_stock_location_id' => $origin->id, 'to_stock_location_id' => $intermediate->id, 'quantity' => '1'],
            ['product_id' => $product->id, 'from_stock_location_id' => $intermediate->id, 'to_stock_location_id' => $destination->id, 'quantity' => '1'],
        ]], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    // Flujo: pérdidas conserva inventory.adjust como autorización suficiente dentro de la transacción.
    public function test_loss_requires_adjust_permission_without_requiring_move(): void
    {
        // Preparación: concede sólo el permiso que exige el contrato de pérdidas.
        $actor = User::factory()->create();
        $actor->givePermissionTo(Permission::findOrCreate('inventory.adjust', 'web'));
        Sanctum::actingAs($actor, ['*']);
        $product = Product::factory()->create();
        $location = StockLocation::factory()->create();
        StockBalance::factory()->create(['product_id' => $product->id, 'stock_location_id' => $location->id, 'on_hand_quantity' => '5']);

        // Request: la autorización de la Action coincide con la del FormRequest.
        $this->postJson('/api/v1/inventory/losses', $this->payload('losses', $product, $location), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->assertDatabaseHas('stock_balances', ['product_id' => $product->id, 'stock_location_id' => $location->id, 'on_hand_quantity' => '4']);
    }

    // Flujo: la demo sanitaria queda reservada sin crear ubicaciones ni existencias físicas.
    public function test_disabled_health_demo_seeder_has_no_inventory_effects(): void
    {
        // Preparación: arma únicamente las referencias que consume el demo de sanidad.
        $actor = $this->admin();
        $actor->forceFill(['email' => config('auth.admin.email')])->save();
        $this->app->instance('env', 'local');
        ProductionUnit::factory()->create();
        Medicine::factory()->create();
        Vaccine::factory()->create();

        // Mutación: repetir el seeder no duplica ni reinicia cantidades.
        $this->seed(HealthStockDemoSeeder::class);
        $before = StockBalance::query()->orderBy('id')->get()->toArray();
        $auditCount = AuditEntry::query()->count();
        $this->seed(HealthStockDemoSeeder::class);
        $this->assertSame($before, StockBalance::query()->orderBy('id')->get()->toArray());
        $this->assertSame($auditCount, AuditEntry::query()->count());
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('stock_balances', 0);
        $this->assertSame(0, StockLocation::query()->where('type', 'health')->count());
    }

    // Flujo: el contrato público permite descubrir sanidad por tipo y UP sin heurísticas.
    public function test_location_list_exposes_type_and_rejects_invalid_type_filter(): void
    {
        // Preparación: crea ubicaciones de tipos distintos en una misma UP.
        $actor = $this->admin();
        $health = $this->location('health', $actor);
        StockLocation::factory()->create(['production_unit_id' => $health->production_unit_id]);

        // Consulta: filtra por tipo explícito y publica sus categorías estables.
        $this->getJson('/api/v1/stock-locations?type=health&production_unit_id='.$health->production_unit_id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'health')->assertJsonPath('data.0.allowed_product_kinds', []);
        $this->getJson('/api/v1/stock-locations?type=invalid')->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    // Flujo: un recuento no puede esconder un producto incompatible dejando su cantidad igual.
    public function test_recount_validates_unchanged_incompatible_lines(): void
    {
        // Preparación: combina un medicamento histórico incompatible con un insumo válido.
        $this->admin();
        $location = StockLocation::factory()->create();
        $medicine = Product::factory()->create(['kind' => 'medicine', 'base_unit' => 'unit']);
        $supply = Product::factory()->create();
        foreach ([$medicine, $supply] as $product) {
            StockBalance::factory()->create(['product_id' => $product->id, 'stock_location_id' => $location->id, 'on_hand_quantity' => '5']);
        }

        // Request: rechaza el conjunto completo aunque sólo el insumo produzca un delta.
        $this->postJson('/api/v1/inventory/adjustments', ['reason' => 'Recuento mixto', 'lines' => [
            ['product_id' => $medicine->id, 'stock_location_id' => $location->id, 'counted_quantity' => '5'],
            ['product_id' => $supply->id, 'stock_location_id' => $location->id, 'counted_quantity' => '6'],
        ]], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseHas('stock_balances', ['product_id' => $supply->id, 'on_hand_quantity' => '5']);
    }

    // Flujo: un traslado de regularización nunca cruza cero en el origen incompatible.
    public function test_regularization_rejects_overdraw_without_changing_source_or_destination(): void
    {
        // Preparación: un saldo histórico pequeño tiene un destino sanitario válido.
        $actor = $this->admin();
        $product = Product::factory()->create(['kind' => 'medicine', 'base_unit' => 'unit']);
        $balance = StockBalance::factory()->create(['product_id' => $product->id, 'on_hand_quantity' => '2']);
        $health = $this->location('health', $actor);

        // Request: intentar trasladar más de lo existente revierte todos los efectos.
        $this->postJson('/api/v1/inventario/saldos/'.$balance->id.'/regularizaciones', [
            'mode' => 'trasladar', 'quantity' => '3', 'to_stock_location_id' => $health->id, 'reason' => 'Cantidad mayor que la existente',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->assertSame('2.000000', (string) $balance->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('stock_balances', 1);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    // Flujo: cierra existencias incompatibles de productos retirados sin reactivar su catálogo.
    public function test_historical_recount_can_clear_inactive_untracked_product_without_reactivation(): void
    {
        // Preparación: conserva un saldo antiguo cuyo producto ya no participa en operaciones nuevas.
        $this->admin();
        $product = Product::factory()->create(['status' => 'inactive', 'stock_tracked' => false]);
        $balance = StockBalance::factory()->create(['product_id' => $product->id, 'on_hand_quantity' => '3']);

        // Request: el cierre compensatorio sólo lleva a cero, sin reactivar ni duplicar producto.
        $this->postJson('/api/v1/inventario/saldos/'.$balance->id.'/regularizaciones', ['mode' => 'recontar', 'reason' => 'Cierre revisado de existencias retiradas'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame('0.000000', (string) $balance->fresh()->on_hand_quantity);
        $this->assertSame('inactive', $product->fresh()->status->value);
        $this->assertFalse($product->fresh()->stock_tracked);
    }

    // Flujo: el comando central también exige autorización cuando se invoca fuera de HTTP.
    public function test_direct_inventory_command_rejects_an_actor_without_move_permission(): void
    {
        // Preparación: un usuario sin permiso no puede escribir mediante el servicio central.
        $actor = User::factory()->create();
        $this->expectException(AuthorizationException::class);

        // Mutación: la autorización precede a cualquier lectura o modificación de stock.
        app(RecordInventoryMovementAction::class)->execute(new InventoryMovementCommand(
            type: InventoryMovementType::Receipt, lines: [], operationId: (string) Str::uuid(),
        ), $actor);
    }

    // Flujo: verifica rutas y referencias del contrato público de almacenamiento.
    public function test_storage_contract_resolves_references_and_matches_registered_routes(): void
    {
        // Preparación: obtiene el contrato publicado y el mapa de rutas reales.
        $contract = Yaml::parseFile(base_path('contracts/openapi/inventory-storage.yaml'));
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $routes[$method.' /'.$route->uri()] = true;
            }
        }

        // Consulta: cada operación documentada debe existir con el mismo método y ruta.
        foreach ($contract['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $this->assertArrayHasKey(strtoupper($method).' /api/v1'.$path, $routes);
            }
        }
        $references = [];
        array_walk_recursive($contract, static function (mixed $value, string|int $key) use (&$references): void {
            if ($key === '$ref') {
                $references[] = $value;
            }
        });

        // Consulta: resuelve tanto los componentes locales como los esquemas compartidos de stock.
        foreach ($references as $reference) {
            [$file, $pointer] = explode('#/', $reference);
            $target = $file === '' ? $contract : Yaml::parseFile(base_path('contracts/openapi/'.$file));
            foreach (explode('/', $pointer) as $segment) {
                $this->assertArrayHasKey($segment, $target);
                $target = $target[$segment];
            }
        }
    }

    // Flujo: protege sanidad por su tipo incluso si un registro antiguo no tiene la marca técnica.
    public function test_health_location_cannot_be_reassigned_or_deactivated_through_generic_endpoints(): void
    {
        // Preparación: el tipo explícito debe prevalecer sobre una marca histórica incorrecta.
        $this->admin();
        $location = StockLocation::factory()->create(['type' => 'health', 'production_unit_id' => ProductionUnit::factory(), 'system_managed' => false]);

        // Requests: rechaza cambios genéricos de nombre y estado sin alterar la ubicación.
        $this->patchJson('/api/v1/stock-locations/'.$location->id, ['name' => 'Ubicación reasignada'])->assertConflict();
        $this->patchJson('/api/v1/stock-locations/'.$location->id.'/status', ['status' => 'inactive'])->assertConflict();
        $this->assertSame($location->name, $location->fresh()->name);
        $this->assertSame('active', $location->fresh()->status->value);
    }

    // Flujo: el producto vinculado mantiene su identidad y publica capacidades coherentes con sus guardas.
    public function test_linked_medicine_product_cannot_change_kind_unit_or_stock_tracking(): void
    {
        // Preparación: vincula una ficha a un producto físico válido.
        $this->admin();
        $product = Product::factory()->create(['kind' => 'medicine', 'base_unit' => 'unit', 'stock_tracked' => false]);
        Medicine::factory()->create(['product_id' => $product->id]);

        // Requests: cada atributo estructural se protege antes de escribir o auditar una edición.
        foreach ([['kind' => 'supply'], ['base_unit' => 'kg'], ['stock_tracked' => true]] as $payload) {
            $this->patchJson('/api/v1/products/'.$product->id, $payload)->assertConflict();
        }
        $this->getJson('/api/v1/products/'.$product->id)->assertOk()->assertJsonPath('data.capabilities.editable_fields', ['sku', 'name']);
        $this->assertSame('medicine', $product->fresh()->kind->value);
        $this->assertSame('unit', $product->fresh()->base_unit->value);
        $this->assertFalse($product->fresh()->stock_tracked);
    }

    // Flujo: crear una UP no aprovisiona sanidad y una llamada interna tampoco puede habilitarla.
    public function test_new_production_unit_does_not_provision_disabled_health_storage(): void
    {
        // Preparación: conserva la configuración real deshabilitada y un actor autorizado.
        $actor = $this->admin();
        $this->assertFalse(config('inventory.health_stock_enabled'));
        $attributes = ProductionUnit::factory()->make()->only(['locality_id', 'name', 'address', 'latitude', 'longitude']);

        // Mutación: el alta operativa sólo registra la UP y su propia auditoría.
        $unit = app(CreateProductionUnitAction::class)->execute($attributes, $actor);
        $this->assertSame(0, StockLocation::query()->where('type', 'health')->count());
        $this->assertDatabaseHas('activity_log', ['event' => 'production_unit_created', 'subject_id' => $unit->id]);

        // Mutación: el aprovisionamiento directo reservado para el futuro también queda bloqueado.
        try {
            app(EnsureHealthStockLocationAction::class)->execute($unit, $actor);
            $this->fail('Sanidad no debe aprovisionarse mientras esté deshabilitada.');
        } catch (InventoryConflict $exception) {
            $this->assertSame('El stock físico de sanidad está deshabilitado.', $exception->getMessage());
            $this->assertSame(0, StockLocation::query()->where('type', 'health')->count());
        }
    }

    private function admin(): User
    {
        $actor = User::factory()->create();
        $actor->assignRole(Role::findOrCreate('admin', 'web'));
        foreach (['inventory.view', 'inventory.move', 'inventory.adjust', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Sanctum::actingAs($actor, ['*']);

        return $actor;
    }

    private function location(string $type, User $actor): StockLocation
    {
        $unit = ProductionUnit::factory()->create();
        if ($type === 'health') {
            // Preparación: representa una ubicación histórica, sin ejecutar el aprovisionamiento deshabilitado.
            return StockLocation::factory()->create(['type' => 'health', 'production_unit_id' => $unit->id, 'system_managed' => true]);
        }
        if ($type === 'egg_account') {
            return app(EnsureEggStockAccountAction::class)->execute($unit)->stockLocation;
        }
        if ($type === 'feed_plant') {
            $house = PoultryHouse::factory()->feed()->create(['production_unit_id' => $unit->id]);

            return StockLocation::factory()->create(['type' => $type, 'production_unit_id' => $unit->id, 'poultry_house_id' => $house->id]);
        }

        return StockLocation::factory()->create(['type' => $type, 'production_unit_id' => $unit->id, 'system_managed' => $type === 'unknown', 'name' => 'Nombre arbitrario '.Str::uuid()]);
    }

    private function payload(string $operation, Product $product, StockLocation $location, ?StockLocation $destination = null): array
    {
        $line = ['product_id' => $product->id, 'stock_location_id' => $location->id, 'quantity' => '1'];
        if ($operation === 'adjustments') {
            $line = ['product_id' => $product->id, 'stock_location_id' => $location->id, 'counted_quantity' => '6'];
        }
        if ($operation === 'transfers') {
            $line = ['product_id' => $product->id, 'from_stock_location_id' => $location->id, 'to_stock_location_id' => $destination->id, 'quantity' => '1'];
        }
        $payload = ['lines' => [$line], 'reason' => 'Prueba de compatibilidad'];
        if ($operation === 'receipts') {
            $supplier = Supplier::query()->where('status', 'active')->first() ?? Supplier::factory()->create();
            $payload['supplier_id'] = $supplier->id;
        }

        return $payload;
    }
}
