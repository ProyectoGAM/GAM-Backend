<?php

namespace Tests\Feature\SuppliersAndCatalogs;

use App\Actions\SuppliersAndCatalogs\CreateProductAction;
use App\Actions\SuppliersAndCatalogs\UpdateProductAction;
use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\SuppliersAndCatalogs\SupplierStatus;
use App\Exceptions\SuppliersAndCatalogs\SuppliersAndCatalogsConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\Inventory\StockBalance;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\SuppliersAndCatalogs\Vaccine;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class VaccineEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: crea una vacuna nueva y conserva su identidad de inventario sin movimientos.
    public function test_admin_creates_vaccine_and_replays_its_current_representation(): void
    {
        // Preparación: autentica al administrador y crea el proveedor activo.
        $admin = $this->admin();
        $supplier = Supplier::factory()->create(['name' => 'Proveedor vacuna']);
        Sanctum::actingAs($admin, ['*']);
        $key = (string) Str::uuid();

        // Acción: registra el catálogo usando los nombres públicos en español.
        $payload = ['sku' => 'VAC-100', 'nombre' => 'Vacuna Uno', 'descripcion' => 'Descripción de vacuna', 'detalles' => 'Detalle libre', 'proveedor_id' => $supplier->id];
        $response = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/vacunas', $payload)
            ->assertCreated()
            ->assertJsonPath('data.sku', 'VAC-100')
            ->assertJsonPath('data.nombre', 'Vacuna Uno')
            ->assertJsonPath('data.unidad_base', 'dose')
            ->assertJsonPath('data.proveedor.nombre_al_asociar', 'Proveedor vacuna');

        // Verificación: asocia un solo producto y audita la operación sin afectar inventario.
        $vaccine = Vaccine::query()->sole();
        $this->assertDatabaseHas('products', ['id' => $vaccine->product_id, 'kind' => 'vaccine', 'stock_tracked' => true]);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseHas('activity_log', ['event' => 'vaccine_created', 'operation_id' => $vaccine->operation_id]);
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/vacunas', $payload)
            ->assertOk()->assertJsonPath('data.id', $response->json('data.id'));
        $this->assertDatabaseCount('vaccines', 1);
    }

    // Flujo: adopta el SKU demo y permite editar y reactivar la ficha administrada.
    public function test_admin_adopts_existing_product_and_updates_its_catalog_fields(): void
    {
        // Preparación: crea un artículo vacuna con saldo histórico y un administrador.
        $admin = $this->admin();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->vaccine()->create(['sku' => 'VAC-EXISTE', 'name' => 'Vacuna existente']);
        Sanctum::actingAs($admin, ['*']);

        // Acción: adopta el producto sin reemplazarlo y cambia los textos de la ficha.
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/vacunas', ['sku' => $product->sku, 'nombre' => $product->name, 'descripcion' => 'Original', 'proveedor_id' => $supplier->id])
            ->assertCreated()->assertJsonPath('data.producto_id', $product->id);
        $vaccine = Vaccine::query()->sole();
        $this->patchJson('/api/v1/vacunas/'.$vaccine->public_id, ['nombre' => 'Vacuna corregida', 'detalles' => 'Libre'])
            ->assertOk()->assertJsonPath('data.nombre', 'Vacuna corregida');

        // Verificación: el producto canónico cambió de nombre y el vínculo sigue estable.
        $this->assertSame('Vacuna corregida', $product->fresh()->name);
        $this->assertSame($product->id, $vaccine->fresh()->product_id);
        $this->assertSame(1, AuditEntry::query()->where('event', 'vaccine_updated')->count());
    }

    // Flujo: restringe la sección a administradores y protege la baja con existencias.
    public function test_only_admins_can_manage_vaccines_and_inactivation_rejects_positive_stock(): void
    {
        // Preparación: autentica un gestor de productos sin rol administrador.
        $manager = User::factory()->create();
        $manager->givePermissionTo(Permission::findOrCreate('products.manage', 'web'));
        Sanctum::actingAs($manager, ['*']);
        $this->getJson('/api/v1/vacunas')->assertForbidden();

        // Preparación: crea una ficha administrada con saldo positivo.
        $admin = $this->admin();
        Sanctum::actingAs($admin, ['*']);
        $vaccine = Vaccine::factory()->create();
        StockBalance::factory()->create(['product_id' => $vaccine->product_id, 'on_hand_quantity' => '1.000000']);

        // Acción: intenta desactivar y comprueba el conflicto de negocio.
        $this->patchJson('/api/v1/vacunas/'.$vaccine->public_id.'/estado', ['estado' => 'inactive'])->assertConflict();
        $this->assertSame('active', $vaccine->product->fresh()->status->value);
    }

    // Flujo: revierte creación, edición y transición si no puede persistir la auditoría obligatoria.
    public function test_audit_failure_rolls_back_vaccine_writes(): void
    {
        // Preparación: reemplaza el recorder y prepara un administrador con proveedor.
        $admin = $this->admin();
        $supplier = Supplier::factory()->create();
        Sanctum::actingAs($admin, ['*']);
        $recorder = app(AuditRecorder::class);
        $this->app->instance(AuditRecorder::class, new class($recorder) implements AuditRecorder
        {
            private int $records = 0;

            public function __construct(private AuditRecorder $delegate) {}

            public function record(AuditEntryData $entry): void
            {
                $this->records++;
                if ($this->records === 2) {
                    throw new RuntimeException('Fallo de auditoría.');
                }

                $this->delegate->record($entry);
            }
        });
        $key = (string) Str::uuid();

        // Acción: persiste la primera auditoría y falla la segunda dentro de la misma transacción.
        $payload = [
            'sku' => 'VAC-ROLLBACK', 'nombre' => 'Vacuna rollback', 'descripcion' => 'Ficha', 'proveedor_id' => $supplier->id,
        ];
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/vacunas', $payload)->assertStatus(500);

        // Verificación: revierte producto, ficha y la primera entrada de auditoría ya persistida.
        $this->assertDatabaseMissing('products', ['sku' => 'VAC-ROLLBACK']);
        $this->assertDatabaseCount('vaccines', 0);
        $this->assertDatabaseCount('activity_log', 0);
        $this->app->instance(AuditRecorder::class, $recorder);

        // Acción: reintenta el mismo comando luego de restaurar la auditoría obligatoria.
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/vacunas', $payload)->assertCreated();
    }

    // Flujo: conserva nombre y estado cuando fallan las auditorías de edición o transición.
    public function test_audit_failure_rolls_back_vaccine_update_and_status(): void
    {
        // Preparación: crea una ficha confirmada y conserva el estado previo de Producto y Vacuna.
        $admin = $this->admin();
        $vaccine = Vaccine::factory()->create();
        Sanctum::actingAs($admin, ['*']);
        $recorder = app(AuditRecorder::class);
        $originalName = $vaccine->product->name;
        $originalUpdatedAt = $vaccine->updated_at;

        // Acción: persiste la auditoría de Producto y falla la segunda al editar la ficha.
        $this->app->instance(AuditRecorder::class, $this->failSecondAudit($recorder));
        $this->patchJson('/api/v1/vacunas/'.$vaccine->public_id, ['nombre' => 'Nombre no confirmado'])->assertStatus(500);
        $this->assertSame($originalName, $vaccine->product->fresh()->name);
        $this->assertEquals($originalUpdatedAt, $vaccine->fresh()->updated_at);
        $this->assertDatabaseCount('activity_log', 0);

        // Acción: usa un contador independiente para persistir la primera auditoría de estado y fallar la segunda.
        $this->app->instance(AuditRecorder::class, $this->failSecondAudit($recorder));
        $this->patchJson('/api/v1/vacunas/'.$vaccine->public_id.'/estado', ['estado' => 'inactive'])->assertStatus(500);
        $this->assertSame('active', $vaccine->product->fresh()->status->value);
        $this->assertEquals($originalUpdatedAt, $vaccine->fresh()->updated_at);
        $this->assertDatabaseCount('activity_log', 0);
        $this->app->instance(AuditRecorder::class, $recorder);
    }

    // Flujo: limita payloads, proveedor activo, filtros y los bypasses de Productos.
    public function test_validation_listing_and_product_guards_preserve_vaccine_invariants(): void
    {
        // Preparación: crea datos activos e inactivos y autentica al administrador.
        $admin = $this->admin();
        $supplier = Supplier::factory()->create();
        $inactiveSupplier = Supplier::factory()->create(['status' => SupplierStatus::Inactive]);
        $listed = Vaccine::factory()->for($supplier)->create(['description' => 'Ficha consultable']);
        Sanctum::actingAs($admin, ['*']);

        // Acción: rechaza el PATCH vacío, los campos desconocidos y una asociación inactiva.
        $this->patchJson('/api/v1/vacunas/'.$listed->public_id, [])->assertUnprocessable()->assertJsonValidationErrors(['body']);
        $this->patchJson('/api/v1/vacunas/'.$listed->public_id, ['sku' => 'NO-PERMITIDO'])->assertUnprocessable()->assertJsonValidationErrors(['sku']);
        foreach ([['nombre' => []], ['nombre' => null], ['nombre' => '']] as $invalidPayload) {
            $this->patchJson('/api/v1/vacunas/'.$listed->public_id, $invalidPayload)->assertUnprocessable()->assertJsonValidationErrors(['nombre']);
        }
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/vacunas', [
            'sku' => 'VAC-INACTIVA', 'nombre' => 'Vacuna inactiva', 'descripcion' => 'Ficha', 'proveedor_id' => $inactiveSupplier->id,
        ])->assertConflict();

        // Consulta: encuentra por nombre y SKU literal, y preserva la paginación pública.
        $this->getJson('/api/v1/vacunas?buscar='.urlencode($listed->product->sku).'&per_page=1')
            ->assertOk()->assertJsonPath('data.0.id', $listed->public_id)->assertJsonPath('meta.per_page', 1);
        $this->getJson('/api/v1/vacunas?buscar='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');

        // Acción: prueba los guards de la API genérica de Productos sobre el vínculo.
        $this->patchJson('/api/v1/products/'.$listed->product_id, ['sku' => 'OTRO-SKU'])->assertConflict();
        $manager = User::factory()->create();
        $manager->givePermissionTo(Permission::findOrCreate('products.manage', 'web'));
        Sanctum::actingAs($manager, ['*']);
        $this->patchJson('/api/v1/products/'.$listed->product_id, ['name' => 'Cambio sin rol'])->assertForbidden();
    }

    // Flujo: protege los campos reservados aun cuando las acciones de Productos se invocan directamente.
    public function test_direct_product_actions_reject_reserved_attributes_without_side_effects(): void
    {
        // Preparación: crea una vacuna con saldo y captura su estado, auditoría e inventario iniciales.
        $admin = $this->admin();
        $vaccine = Vaccine::factory()->create();
        $balance = StockBalance::factory()->create(['product_id' => $vaccine->product_id, 'on_hand_quantity' => '3.000000']);
        $product = $vaccine->product->fresh();
        $before = [$product->getAttributes(), $balance->fresh()->getAttributes(), AuditEntry::query()->count()];

        // Acción: intenta eludir la transición de estado y modificar la clave técnica por la acción directa.
        foreach ([['status' => 'inactive'], ['system_key' => 'generic_egg']] as $attributes) {
            try {
                app(UpdateProductAction::class)->execute($product, $attributes, $admin);
                $this->fail('La acción debía rechazar atributos reservados.');
            } catch (SuppliersAndCatalogsConflict $exception) {
                $this->assertStringContainsString('no están permitidos', $exception->getMessage());
            }
        }
        try {
            app(CreateProductAction::class)->execute([
                'sku' => 'RESERVADO-001', 'name' => 'Producto reservado', 'kind' => 'supply', 'base_unit' => 'unit', 'stock_tracked' => true, 'system_key' => 'generic_egg',
            ], $admin);
            $this->fail('La creación debía rechazar la clave técnica reservada.');
        } catch (SuppliersAndCatalogsConflict $exception) {
            $this->assertStringContainsString('no están permitidos', $exception->getMessage());
        }

        // Verificación: no altera estado, saldo, auditoría ni crea un producto técnico por bypass.
        $this->assertSame($before, [$product->fresh()->getAttributes(), $balance->fresh()->getAttributes(), AuditEntry::query()->count()]);
        $this->assertDatabaseMissing('products', ['sku' => 'RESERVADO-001']);
        $this->assertDatabaseMissing('activity_log', ['event' => 'product_status_changed', 'subject_id' => $product->id]);
    }

    // Flujo: conserva una operación de auditoría común cuando Producto modifica una vacuna vinculada.
    public function test_generic_product_routes_audit_and_touch_the_linked_vaccine(): void
    {
        // Preparación: crea una vacuna editable sin movimientos y autentica al administrador.
        $admin = $this->admin();
        $vaccine = Vaccine::factory()->create();
        $originalUpdatedAt = $vaccine->updated_at;
        Sanctum::actingAs($admin, ['*']);

        // Acción: actualiza nombre y unidad por la ruta genérica de Productos.
        $this->travel(1)->seconds();
        $this->patchJson('/api/v1/products/'.$vaccine->product_id, ['name' => 'Vacuna actualizada por producto', 'base_unit' => 'kg'])
            ->assertOk()->assertJsonPath('data.base_unit', 'kg');

        // Verificación: ambas entradas comparten operación, detallan la unidad y actualizan la ficha.
        $productAudit = AuditEntry::query()->where('event', 'product_updated')->where('subject_id', $vaccine->product_id)->sole();
        $vaccineAudit = AuditEntry::query()->where('event', 'vaccine_updated')->where('subject_id', $vaccine->id)->sole();
        $changes = json_decode((string) DB::table('activity_log')->where('id', $vaccineAudit->id)->value('attribute_changes'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($productAudit->operation_id, $vaccineAudit->operation_id);
        $this->assertSame('dose', $changes['old']['base_unit']);
        $this->assertSame('kg', $changes['new']['base_unit']);
        $this->assertGreaterThan($originalUpdatedAt, $vaccine->fresh()->updated_at);

        // Acción: cambia el estado mediante la ruta genérica y repite la misma transición sin cambios.
        $this->patchJson('/api/v1/products/'.$vaccine->product_id.'/status', ['status' => 'inactive'])->assertOk();
        $statusProductAudit = AuditEntry::query()->where('event', 'product_status_changed')->where('subject_id', $vaccine->product_id)->sole();
        $statusVaccineAudit = AuditEntry::query()->where('event', 'vaccine_status_changed')->where('subject_id', $vaccine->id)->sole();
        $auditCount = AuditEntry::query()->count();
        $updatedAtAfterStatus = $vaccine->fresh()->updated_at;
        $this->patchJson('/api/v1/products/'.$vaccine->product_id.'/status', ['status' => 'inactive'])->assertOk();

        // Verificación: la transición comparte operación y el no-op no toca ni vuelve a auditar la ficha.
        $this->assertSame($statusProductAudit->operation_id, $statusVaccineAudit->operation_id);
        $this->assertSame($auditCount, AuditEntry::query()->count());
        $this->assertEquals($updatedAtAfterStatus, $vaccine->fresh()->updated_at);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'web'));

        return $user;
    }

    private function failSecondAudit(AuditRecorder $delegate): AuditRecorder
    {
        return new class($delegate) implements AuditRecorder
        {
            private int $records = 0;

            public function __construct(private AuditRecorder $delegate) {}

            public function record(AuditEntryData $entry): void
            {
                $this->records++;
                if ($this->records === 2) {
                    throw new RuntimeException('Fallo de auditoría.');
                }

                $this->delegate->record($entry);
            }
        };
    }
}
