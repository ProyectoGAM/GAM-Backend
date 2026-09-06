<?php

namespace Tests\Feature\SuppliersAndCatalogs;

use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\Inventory\StockBalance;
use App\Models\Lots\Flock;
use App\Models\SuppliersAndCatalogs\Medicine;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\SuppliersAndCatalogs\Application\Actions\CreateMedicineAction;
use App\Modules\SuppliersAndCatalogs\Domain\Exceptions\MedicineConflict;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class MedicineEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: registra los tres datos del catálogo y audita sin modificar otros dominios.
    public function test_admin_creates_medicine_with_snapshot_and_audit_returns_201(): void
    {
        // Preparación: fija tiempo, administrador y datos ajenos que deben conservarse.
        $this->travelTo('2026-09-05T12:00:00+00:00');
        $admin = $this->admin();
        $supplier = Supplier::factory()->create(['name' => 'Proveedor original']);
        $flock = Flock::factory()->create();
        $house = PoultryHouse::query()->findOrFail($flock->poultry_house_id);
        $balance = StockBalance::factory()->create(['on_hand_quantity' => '100.000000']);
        $before = [$flock->fresh()->getAttributes(), $house->fresh()->getAttributes(), $balance->fresh()->getAttributes()];
        Sanctum::actingAs($admin, ['*']);

        // Acción: registra sin fechas operativas ni dosis.
        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/medicamentos', $this->payload($supplier))->assertCreated()
            ->assertJsonPath('data.nombre', 'Medicamento A')
            ->assertJsonPath('data.descripcion', 'Descripción de catálogo')
            ->assertJsonPath('data.proveedor.id', $supplier->id)
            ->assertJsonPath('data.proveedor.nombre_al_registrar', 'Proveedor original')
            ->assertJsonPath('data.registrado_por.id', $admin->id)
            ->assertJsonPath('data.registrado_en', '2026-09-05T12:00:00+00:00');

        // Consulta: comprueba la persistencia, la correlación y la ausencia de efectos ajenos.
        $medicine = Medicine::query()->sole();
        $audit = AuditEntry::query()->where('event', 'medicine_created')->sole();
        $this->assertSame($medicine->public_id, $response->json('data.id'));
        $this->assertTrue(Str::isUlid($medicine->public_id));
        $this->assertSame($medicine->operation_id, $response->json('data.id_operacion'));
        $this->assertSame($medicine->operation_id, $audit->operation_id);
        $this->assertSame($admin->id, $audit->causer_id);
        $this->assertSame('suppliers_and_catalogs', $audit->log_name);
        $this->assertNotEmpty($audit->trace_id);
        $this->assertNull($audit->up_id);
        $this->assertSame('Proveedor original', $audit->properties['subject_snapshot']['supplier_name_snapshot']);
        $this->assertSame([], $audit->attribute_changes['old']);
        $this->assertSame('Medicamento A', $audit->attribute_changes['new']['name']);
        $this->assertSame($before, [$flock->fresh()->getAttributes(), $house->fresh()->getAttributes(), $balance->fresh()->getAttributes()]);
        $this->assertDatabaseCount('products', 1);
        foreach (['flock_movements', 'flock_operations', 'inventory_movements', 'inventory_movement_lines'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $response->assertJsonMissingPath('data.request_hash')->assertJsonMissingPath('data.idempotency_key')
            ->assertJsonMissingPath('data.registrado_por.email');
    }

    // Flujo: exige autenticación en ambas operaciones y rechaza gestores sin rol administrador.
    public function test_endpoints_return_401_without_token_and_403_for_non_admin(): void
    {
        // Consulta: intenta acceder sin sesión.
        $this->getJson('/api/v1/medicamentos')->assertUnauthorized();
        $this->postJson('/api/v1/medicamentos', [])->assertUnauthorized();

        // Preparación: concede permisos de catálogo sin conceder el rol administrador.
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('products.manage', 'web'), Permission::findOrCreate('products.view', 'web'));
        Sanctum::actingAs($user, ['*']);

        // Acción: ambos endpoints deben aplicar la policy específica.
        $this->getJson('/api/v1/medicamentos')->assertForbidden();
        $this->postJson('/api/v1/medicamentos', [])->assertForbidden();
        $this->assertDatabaseCount('medicines', 0);
    }

    // Flujo: verifica la matriz de autorización, incluida la baja del administrador.
    public function test_policy_only_allows_active_admin(): void
    {
        // Preparación: construye los actores y desactiva uno.
        $admin = $this->admin();
        $inactive = $this->admin();
        $inactive->delete();
        $user = User::factory()->create();

        // Consulta: comprueba ambas capacidades sin depender de un rechazo HTTP previo.
        foreach (['viewAny', 'create'] as $ability) {
            $this->assertTrue(Gate::forUser($admin)->allows($ability, Medicine::class));
            $this->assertFalse(Gate::forUser($inactive)->allows($ability, Medicine::class));
            $this->assertFalse(Gate::forUser($user)->allows($ability, Medicine::class));
        }
        Sanctum::actingAs($inactive, ['*']);
        $this->getJson('/api/v1/medicamentos')->assertForbidden();
        $this->postJson('/api/v1/medicamentos', [])->assertForbidden();
    }

    // Flujo: protege el caso de uso cuando se invoca directamente sin autorización.
    public function test_action_rejects_non_admin_outside_http(): void
    {
        // Preparación: utiliza un usuario sin privilegios.
        $user = User::factory()->create();
        $this->expectException(AuthorizationException::class);

        // Acción: la autorización ocurre antes de procesar atributos.
        app(CreateMedicineAction::class)->execute([], $user);
    }

    // Flujo: rechaza atributos inválidos con un error localizado y sin persistencia parcial.
    #[DataProvider('invalidPayloads')]
    public function test_invalid_payload_returns_422(string $field, mixed $value, string $message): void
    {
        // Preparación: parte de datos válidos y cambia sólo el campo bajo prueba.
        Sanctum::actingAs($this->admin(), ['*']);
        $data = $this->payload(Supplier::factory()->create());
        $data[$field] = $value;

        // Acción: valida estructura y restricciones de entrada.
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/medicamentos', $data)
            ->assertUnprocessable()->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('errors.'.$field.'.0', $message);
        $this->assertDatabaseCount('medicines', 0);
        $this->assertDatabaseCount('activity_log', 0);
    }

    /** @return array<string, array{string, mixed, string}> */
    public static function invalidPayloads(): array
    {
        $unknown = 'El campo no está permitido en esta operación.';

        return [
            'nombre vacío' => ['nombre', '   ', 'Debes indicar el nombre del medicamento.'],
            'nombre largo' => ['nombre', str_repeat('a', 161), 'El nombre no puede superar los 160 caracteres.'],
            'nombre no textual' => ['nombre', 42, 'El nombre debe ser texto.'],
            'descripción vacía' => ['descripcion', '', 'Debes indicar la descripción del medicamento.'],
            'descripción larga' => ['descripcion', str_repeat('a', 5001), 'La descripción no puede superar los 5000 caracteres.'],
            'descripción no textual' => ['descripcion', [], 'Debes indicar la descripción del medicamento.'],
            'proveedor ausente' => ['proveedor_id', null, 'Debes seleccionar un proveedor.'],
            'proveedor negativo' => ['proveedor_id', -1, 'El proveedor debe ser un identificador positivo.'],
            'proveedor no entero' => ['proveedor_id', 'abc', 'El proveedor debe ser un identificador entero.'],
            'proveedor fuera de rango' => ['proveedor_id', '9223372036854775808', 'El proveedor debe ser un identificador entero.'],
            'fecha pasada' => ['ocurrido_en', '2020-01-01', $unknown],
            'fecha futura' => ['programado_para', '2030-01-01', $unknown],
            'dosis' => ['dosis', '15ml', $unknown],
            'plan' => ['plan_manejo_id', 1, $unknown],
            'lote' => ['lote_id', '01AAAAAAAAAAAAAAAAAAAAAAAA', $unknown],
            'actor' => ['created_by', 1, $unknown],
            'clave en el cuerpo' => ['idempotency_key', '00000000-0000-4000-8000-000000000001', 'La clave de idempotencia sólo se admite en el encabezado Idempotency-Key.'],
            'estado' => ['estado', 'active', $unknown],
        ];
    }

    // Flujo: exige los campos mínimos y el encabezado técnico.
    public function test_missing_fields_and_invalid_idempotency_key_return_422(): void
    {
        // Preparación: autentica al administrador.
        Sanctum::actingAs($this->admin(), ['*']);

        // Acción: comprueba ausencia y formato de la clave.
        $this->postJson('/api/v1/medicamentos', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['nombre', 'descripcion', 'proveedor_id', 'idempotency_key'])
            ->assertJsonPath('errors.idempotency_key.0', 'El encabezado Idempotency-Key es obligatorio.');
        $this->withHeader('Idempotency-Key', 'invalid')->postJson('/api/v1/medicamentos', $this->payload(Supplier::factory()->create()))
            ->assertUnprocessable()->assertJsonPath('errors.idempotency_key.0', 'El encabezado Idempotency-Key debe ser un UUID válido.');
    }

    // Flujo: distingue proveedor inexistente de proveedor desactivado.
    public function test_supplier_missing_returns_404_and_inactive_returns_409(): void
    {
        // Preparación: autentica y crea una referencia desactivada.
        Sanctum::actingAs($this->admin(), ['*']);
        $supplier = Supplier::factory()->create(['status' => 'inactive']);
        $data = $this->payload($supplier);

        // Acción: intenta ambos casos sin registrar medicamentos ni auditoría.
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/medicamentos', $data)
            ->assertConflict()->assertJsonPath('detail', 'El proveedor debe estar activo para registrar un medicamento.');
        $data['proveedor_id'] = 9223372036854775807;
        $this->postJson('/api/v1/medicamentos', $data)->assertNotFound();
        $this->assertDatabaseCount('medicines', 0);
        $this->assertDatabaseCount('activity_log', 0);
    }

    // Flujo: los reintentos conservan identidad y snapshots aun si cambian las referencias.
    public function test_replay_preserves_original_response_and_rejects_changed_payload_with_409(): void
    {
        // Preparación: registra un medicamento con una clave conocida.
        $admin = $this->admin();
        Sanctum::actingAs($admin, ['*']);
        $supplier = Supplier::factory()->create(['name' => 'Proveedor original']);
        $data = $this->payload($supplier);
        $key = (string) Str::uuid();
        $first = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/medicamentos', $data)->assertCreated();

        // Mutación: cambia referencias después de la operación ya confirmada.
        $supplier->update(['name' => 'Proveedor renombrado', 'status' => 'inactive']);
        $admin->update(['name' => 'Administrador renombrado']);

        // Acción: normaliza claves y textos y devuelve la operación original.
        $reordered = ['proveedor_id' => (string) $supplier->id, 'descripcion' => ' Descripción de catálogo ', 'nombre' => ' Medicamento A '];
        $this->withHeader('Idempotency-Key', strtoupper($key))->postJson('/api/v1/medicamentos', $reordered)
            ->assertCreated()->assertExactJson($first->json());
        $this->getJson('/api/v1/medicamentos')->assertOk()->assertJsonPath('data.0.proveedor.nombre_al_registrar', 'Proveedor original');

        // Acción: la misma clave con otros datos no crea otro medicamento.
        $data['nombre'] = 'Otro medicamento';
        $this->postJson('/api/v1/medicamentos', $data)->assertConflict()
            ->assertJsonPath('detail', 'La clave de idempotencia ya fue utilizada con otros datos.');
        $this->assertDatabaseCount('medicines', 1);
        $this->assertDatabaseCount('activity_log', 1);
    }

    // Flujo: admite nombres repetidos y aísla claves iguales de administradores distintos.
    public function test_duplicate_names_and_actor_scoped_keys_are_allowed(): void
    {
        // Preparación: comparte proveedor, nombre y clave entre administradores.
        $data = $this->payload(Supplier::factory()->create());
        $key = (string) Str::uuid();
        Sanctum::actingAs($this->admin(), ['*']);

        // Acción: registra otro hecho con una clave diferente y luego con otro actor.
        $first = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/medicamentos', $data)->assertCreated();
        $second = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/medicamentos', $data)->assertCreated();
        Sanctum::actingAs($this->admin(), ['*']);
        $third = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/medicamentos', $data)->assertCreated();
        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertNotSame($first->json('data.id'), $third->json('data.id'));
        $this->assertDatabaseCount('medicines', 3);
        $this->assertDatabaseCount('activity_log', 3);
    }

    // Flujo: una auditoría fallida revierte el alta y permite reintentar la clave.
    public function test_audit_failure_rolls_back_and_does_not_consume_key(): void
    {
        // Preparación: conserva el recorder real y simula únicamente su fallo.
        Sanctum::actingAs($this->admin(), ['*']);
        $data = $this->payload(Supplier::factory()->create());
        $recorder = app(AuditRecorder::class);
        $this->mock(AuditRecorder::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit storage failed.'));

        // Acción: el error no debe dejar un registro parcial.
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/medicamentos', $data)->assertStatus(500);
        $this->assertDatabaseCount('medicines', 0);
        $this->assertDatabaseCount('activity_log', 0);

        // Acción: restaura almacenamiento y reintenta exactamente la misma solicitud.
        $this->app->instance(AuditRecorder::class, $recorder);
        $this->postJson('/api/v1/medicamentos', $data)->assertCreated();
        $this->assertDatabaseCount('medicines', 1);
        $this->assertDatabaseCount('activity_log', 1);
    }

    // Flujo: el caso de uso protege datos inválidos también desde un seeder.
    public function test_action_rejects_invalid_business_values(): void
    {
        // Preparación: usa un administrador y un nombre vacío.
        $admin = $this->admin();
        $this->expectException(MedicineConflict::class);

        // Acción: invoca la frontera de aplicación sin FormRequest.
        app(CreateMedicineAction::class)->execute(['name' => ' ', 'description' => 'Detalle', 'supplier_id' => 1, 'idempotency_key' => (string) Str::uuid()], $admin);
    }

    // Flujo: recorre páginas estables y filtra nombre literal y proveedor.
    public function test_list_filters_and_pagination_preserve_order_and_query_string(): void
    {
        // Preparación: mezcla proveedores, nombres y empates de creación.
        $this->freezeTime();
        Sanctum::actingAs($this->admin(), ['*']);
        $supplier = Supplier::factory()->create();
        $old = Medicine::factory()->for($supplier)->create(['name' => 'Medicina', 'created_at' => now()->subDay()]);
        $first = Medicine::factory()->for($supplier)->create(['name' => 'Medicina 15%']);
        $last = Medicine::factory()->for($supplier)->create(['name' => 'Medicina 50%']);
        Medicine::factory()->create(['name' => 'Medicina de otro proveedor']);

        // Consulta: limita por proveedor y nombre, siguiendo el enlace generado.
        $response = $this->getJson('/api/v1/medicamentos?buscar=medicina&proveedor_id='.$supplier->id.'&por_pagina=2')
            ->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.id', $last->public_id)
            ->assertJsonPath('data.1.id', $first->public_id);
        $next = $response->json('links.next');
        $this->assertStringContainsString('pagina=2', $next);
        $this->assertStringContainsString('buscar=medicina', $next);
        $this->assertStringContainsString('proveedor_id='.$supplier->id, $next);
        $this->getJson($next)->assertOk()->assertJsonPath('data.0.id', $old->public_id)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/medicamentos?buscar=15%25')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/medicamentos?buscar='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/medicamentos?proveedor_id=9223372036854775807')->assertOk()->assertJsonCount(0, 'data');
    }

    // Flujo: limita filtros y confirma que no hay operaciones públicas de edición.
    public function test_list_rejects_unbounded_filters_and_catalog_has_no_mutation_routes(): void
    {
        // Preparación: autentica al administrador sin cargar registros.
        Sanctum::actingAs($this->admin(), ['*']);

        // Consulta: responde vacío y rechaza límites o filtros no permitidos.
        $this->getJson('/api/v1/medicamentos')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.per_page', 50);
        $this->getJson('/api/v1/medicamentos?por_pagina=101&pagina=0&proveedor_id=0&buscar='.str_repeat('a', 161).'&lote_id=1')
            ->assertUnprocessable()->assertJsonValidationErrors(['por_pagina', 'pagina', 'proveedor_id', 'buscar', 'lote_id']);
        $this->patchJson('/api/v1/medicamentos', [])->assertMethodNotAllowed();
        $this->deleteJson('/api/v1/medicamentos')->assertMethodNotAllowed();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'web'));

        return $user;
    }

    /** @return array{nombre: string, descripcion: string, proveedor_id: int} */
    private function payload(Supplier $supplier): array
    {
        return ['nombre' => 'Medicamento A', 'descripcion' => 'Descripción de catálogo', 'proveedor_id' => $supplier->id];
    }
}
