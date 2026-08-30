<?php

namespace Tests\Feature\FarmStructure;

use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\FarmStructure\Maintenance;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class MaintenanceEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: registra un hecho pasado con precisión monetaria y auditoría en la misma operación.
    public function test_creation_preserves_exact_cost_and_audits_without_changing_house(): void
    {
        // Preparación: fija fecha y crea un galpón y un responsable autorizado.
        $this->travelTo(now()->setDate(2026, 8, 30)->startOfDay());
        $house = PoultryHouse::factory()->maintenance()->create();
        $actor = $this->userWithPermissions(['poultry-houses.manage']);
        Sanctum::actingAs($actor, ['*']);
        $before = $house->refresh()->getAttributes();
        $payload = $this->payload($actor);
        $payload['cost_amount'] = '999999999999999.99';

        // Acción: registra el mantenimiento y comprueba el contrato de salida.
        $response = $this->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->postJson($this->collectionUrl($house), $payload)
            ->assertCreated()
            ->assertJsonPath('data.cost.amount', '999999999999999.99')
            ->assertJsonPath('data.cost.currency', 'UYU')
            ->assertJsonPath('data.responsible.name', $actor->name)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.version', 1);
        $id = $response->json('data.id');

        // Verificación: conserva instalaciones y sólo expone datos permitidos en el histórico.
        $this->assertDatabaseHas('maintenances', ['id' => $id, 'cost_amount' => '999999999999999.9900']);
        $this->assertSame($before, $house->fresh()->getAttributes());
        $entry = AuditEntry::query()->where('event', 'maintenance_created')->sole();
        $this->assertSame($actor->id, $entry->causer_id);
        $this->assertSame($house->production_unit_id, $entry->up_id);
        $this->assertNotEmpty($entry->operation_id);
        $this->assertNotEmpty($entry->trace_id);
        $this->assertSame('999999999999999.99', $entry->properties['subject_snapshot']['cost']['amount']);
        $response->assertJsonMissingPath('data.idempotency_key')->assertJsonMissingPath('data.responsible.email');
    }

    // Flujo: reintenta el alta y rechaza la reutilización de clave con otro contenido.
    public function test_create_is_idempotent_and_rejects_different_payload(): void
    {
        // Preparación: crea contexto y una clave de reintento estable.
        $house = PoultryHouse::factory()->create();
        $actor = $this->userWithPermissions(['poultry-houses.manage']);
        Sanctum::actingAs($actor, ['*']);
        $payload = $this->payload($actor);
        $this->withHeader('Idempotency-Key', Str::uuid()->toString());

        // Acción: repite la operación y luego cambia su contenido usando la misma clave.
        $id = $this->postJson($this->collectionUrl($house), $payload)->assertCreated()->json('data.id');
        $this->postJson($this->collectionUrl($house), $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson($this->collectionUrl($house), [...$payload, 'description' => 'Otro trabajo'])
            ->assertConflict()->assertHeader('Content-Type', 'application/problem+json');

        // Verificación: no duplica hechos ni auditorías.
        $this->assertDatabaseCount('maintenances', 1);
        $this->assertDatabaseCount('activity_log', 1);
    }

    // Flujo: valida cada dato inválido antes de guardar o auditar.
    #[DataProvider('invalidPayloads')]
    public function test_invalid_payload_returns_422_without_side_effects(string $field, mixed $value): void
    {
        // Preparación: construye un alta válida y sustituye el campo bajo prueba.
        $this->travelTo(now()->setDate(2026, 8, 30)->startOfDay());
        $house = PoultryHouse::factory()->create();
        $actor = $this->userWithPermissions(['poultry-houses.manage']);
        Sanctum::actingAs($actor, ['*']);

        // Acción: intenta registrar el dato inválido.
        $this->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->postJson($this->collectionUrl($house), [...$this->payload($actor), $field => $value])
            ->assertUnprocessable();

        // Verificación: no deja registros ni auditorías parciales.
        $this->assertDatabaseCount('maintenances', 0);
        $this->assertDatabaseCount('activity_log', 0);
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidPayloads(): array
    {
        return [
            'fecha futura' => ['maintenance_date', '2026-08-31'],
            'fecha imposible' => ['maintenance_date', '2026-02-30'],
            'descripción vacía' => ['description', '   '],
            'descripción extensa' => ['description', str_repeat('a', 5001)],
            'importe negativo' => ['cost_amount', '-0.01'],
            'importe numérico' => ['cost_amount', 1.25],
            'notación científica' => ['cost_amount', '1e3'],
            'demasiados enteros' => ['cost_amount', '1000000000000000'],
            'demasiados decimales' => ['cost_amount', '0.00001'],
            'redondeo implícito' => ['cost_amount', '1.001'],
            'moneda desconocida' => ['cost_currency', 'ZZZ'],
            'moneda minúscula' => ['cost_currency', 'uyu'],
            'responsable inexistente' => ['responsible_user_id', 999999],
            'estado manual' => ['status', 'cancelled'],
            'cambio de galpón' => ['poultry_house_id', 123],
            'programación' => ['scheduled_for', '2026-09-30'],
        ];
    }

    // Flujo: exige los campos mínimos y el encabezado de idempotencia.
    public function test_missing_fields_and_missing_key_are_localized(): void
    {
        // Preparación: crea contexto autorizado.
        $house = PoultryHouse::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['poultry-houses.manage']), ['*']);

        // Acción: envía una solicitud vacía.
        $this->postJson($this->collectionUrl($house), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['maintenance_date', 'description', 'cost_amount', 'cost_currency', 'responsible_user_id', 'idempotency_key'])
            ->assertJsonPath('errors.maintenance_date.0', 'Debes indicar la fecha del mantenimiento.')
            ->assertJsonPath('errors.idempotency_key.0', 'El encabezado Idempotency-Key es obligatorio.');
    }

    // Flujo: recorre un historial con empates cronológicos, paginación y registros cancelados.
    public function test_history_is_scoped_paginated_and_ordered_by_date_then_id(): void
    {
        // Preparación: mezcla fechas, estados y un mantenimiento de otro galpón.
        $house = PoultryHouse::factory()->create();
        $old = Maintenance::factory()->for($house)->create(['maintenance_date' => '2026-01-01']);
        $first = Maintenance::factory()->for($house)->create(['maintenance_date' => '2026-08-01']);
        $last = Maintenance::factory()->for($house)->cancelled()->create(['maintenance_date' => '2026-08-01']);
        Maintenance::factory()->create(['maintenance_date' => '2026-08-29']);
        Sanctum::actingAs($this->userWithPermissions(['poultry-houses.view']), ['*']);

        // Acción: consulta páginas sucesivas y filtros individuales.
        $this->getJson($this->collectionUrl($house).'?per_page=2')->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.id', $last->id)->assertJsonPath('data.1.id', $first->id);
        $this->getJson($this->collectionUrl($house).'?per_page=2&page=2')->assertOk()->assertJsonPath('data.0.id', $old->id);
        $this->getJson($this->collectionUrl($house).'?status=cancelled')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->collectionUrl($house).'?date_to=2026-01-31')->assertOk()->assertJsonPath('data.0.id', $old->id)->assertJsonCount(1, 'data');
        $this->getJson($this->collectionUrl($house).'?date_from=2026-08-01&date_to=2026-08-01')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($this->collectionUrl($house).'?date_from=2026-08-01&date_to=2026-01-01')->assertUnprocessable();
        $this->getJson($this->collectionUrl($house).'?per_page=101&page=0')->assertUnprocessable();
    }

    // Flujo: consulta el último trabajo válido y devuelve null cuando no queda ninguno.
    public function test_latest_excludes_cancelled_maintenance_and_handles_empty_history(): void
    {
        // Preparación: crea un galpón vacío y autoriza lectura.
        $house = PoultryHouse::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['poultry-houses.view']), ['*']);

        // Acción: consulta vacío, agrega dos hechos y consulta el último no cancelado.
        $this->getJson($this->collectionUrl($house).'/ultimo')->assertOk()->assertExactJson(['data' => null]);
        $completed = Maintenance::factory()->for($house)->create(['maintenance_date' => '2026-01-01']);
        Maintenance::factory()->for($house)->cancelled()->create(['maintenance_date' => '2026-08-01']);
        $this->getJson($this->collectionUrl($house).'/ultimo')->assertOk()->assertJsonPath('data.id', $completed->id);
    }

    // Flujo: corrige el costo y conserva tanto el alta original como los cambios explícitos.
    public function test_correction_preserves_original_audit_and_rejects_stale_version(): void
    {
        // Preparación: registra un mantenimiento mediante su endpoint.
        $house = PoultryHouse::factory()->create();
        $actor = $this->userWithPermissions(['poultry-houses.manage']);
        Sanctum::actingAs($actor, ['*']);
        $id = $this->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->postJson($this->collectionUrl($house), $this->payload($actor))->assertCreated()->json('data.id');
        $original = AuditEntry::query()->sole()->getAttributes();

        // Acción: corrige el costo y luego intenta sobrescribir con una versión obsoleta.
        $this->patchJson("/api/v1/mantenimientos/{$id}", [
            'version' => 1, 'reason' => 'Corrección de comprobante', 'cost_amount' => '0.10',
        ])->assertOk()->assertJsonPath('data.cost.amount', '0.10')->assertJsonPath('data.version', 2);
        $this->patchJson("/api/v1/mantenimientos/{$id}", [
            'version' => 1, 'reason' => 'Versión vieja', 'description' => 'No guardar',
        ])->assertConflict();

        // Verificación: conserva ambas instantáneas y la entrada inicial intacta.
        $entry = AuditEntry::query()->where('event', 'maintenance_corrected')->sole();
        $changes = $entry->getAttribute('attribute_changes');
        $this->assertSame('1250.50', $changes['old']['cost']['amount']);
        $this->assertSame('0.10', $changes['new']['cost']['amount']);
        $this->assertSame($original, AuditEntry::query()->findOrFail($original['id'])->getAttributes());
        $this->assertDatabaseCount('activity_log', 2);
        $this->assertDatabaseHas('maintenances', ['id' => $id, 'version' => 2, 'cost_amount' => '0.1000']);
    }

    // Flujo: retira un registro con motivo y bloquea nuevas correcciones o cancelaciones.
    public function test_cancellation_preserves_history_and_has_no_delete_endpoint(): void
    {
        // Preparación: crea un mantenimiento y autentica al gestor.
        $maintenance = Maintenance::factory()->create();
        $actor = $this->userWithPermissions(['poultry-houses.manage', 'poultry-houses.view']);
        Sanctum::actingAs($actor, ['*']);
        $url = "/api/v1/mantenimientos/{$maintenance->id}";

        // Acción: valida el motivo y realiza la cancelación.
        $this->postJson($url.'/cancelacion', ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($url.'/cancelacion', ['version' => 1, 'reason' => 'Registro duplicado'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.version', 2);
        $this->postJson($url.'/cancelacion', ['version' => 2, 'reason' => 'Reintento'])->assertConflict();
        $this->patchJson($url, ['version' => 2, 'reason' => 'Cambio', 'description' => 'No guardar'])->assertConflict();
        $this->deleteJson($url)->assertMethodNotAllowed();
        $this->getJson($url)->assertOk()->assertJsonPath('data.cancellation_reason', 'Registro duplicado');
        $this->getJson($this->collectionUrl($maintenance->poultryHouse).'/ultimo')->assertExactJson(['data' => null]);

        // Verificación: el hecho original sigue disponible.
        $this->assertModelExists($maintenance);
        $this->assertDatabaseCount('activity_log', 1);
        $this->assertDatabaseHas('activity_log', ['event' => 'maintenance_cancelled', 'subject_id' => $maintenance->id]);
    }

    // Flujo: rechaza cancelaciones desactualizadas y correcciones sin campos modificables.
    public function test_update_and_cancel_require_current_version_and_real_changes(): void
    {
        // Preparación: crea un registro cuya versión ya cambió.
        $maintenance = Maintenance::factory()->create(['version' => 3]);
        Sanctum::actingAs($this->userWithPermissions(['poultry-houses.manage']), ['*']);
        $url = "/api/v1/mantenimientos/{$maintenance->id}";

        // Acción: intenta cancelar una versión anterior o corregir sin cambios.
        $this->postJson($url.'/cancelacion', ['version' => 1, 'reason' => 'Obsoleto'])->assertConflict();
        $this->patchJson($url, ['version' => 3, 'reason' => 'Sin datos'])->assertUnprocessable()->assertJsonValidationErrors('request');
        $this->patchJson($url, ['description' => 'Nuevo texto', 'reason' => 'Sin versión'])->assertUnprocessable()->assertJsonValidationErrors('version');

        // Verificación: no se altera el registro ni se agregan auditorías.
        $this->assertDatabaseHas('maintenances', ['id' => $maintenance->id, 'version' => 3, 'status' => 'completed']);
        $this->assertDatabaseCount('activity_log', 0);
    }

    // Flujo: registra historia en instalaciones inactivas y conserva el nombre del responsable.
    public function test_history_survives_responsible_rename_and_deactivation_without_unit_assignments(): void
    {
        // Preparación: usa una unidad inactiva y un galpón histórico.
        $unit = ProductionUnit::factory()->inactive()->create();
        $house = PoultryHouse::factory()->for($unit)->create();
        $responsible = User::factory()->create(['name' => 'Responsable original']);
        $actor = $this->userWithPermissions(['poultry-houses.manage', 'poultry-houses.view']);
        Sanctum::actingAs($actor, ['*']);

        // Acción: registra el mantenimiento y después modifica al usuario responsable.
        $id = $this->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->postJson($this->collectionUrl($house), $this->payload($responsible))->assertCreated()->json('data.id');
        $responsible->update(['name' => 'Nombre posterior']);
        $responsible->delete();
        $this->getJson("/api/v1/mantenimientos/{$id}")->assertOk()->assertJsonPath('data.responsible.name', 'Responsable original');
        $this->patchJson("/api/v1/mantenimientos/{$id}", ['version' => 1, 'reason' => 'Descripción', 'description' => 'Detalle corregido'])->assertOk();
        $this->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->postJson($this->collectionUrl($house), $this->payload($responsible))->assertUnprocessable()->assertJsonValidationErrors('responsible_user_id');
    }

    // Flujo: obliga a autenticación en todas las operaciones públicas.
    public function test_all_endpoints_require_authentication(): void
    {
        // Preparación: crea referencias válidas para evitar falsos positivos por inexistencia.
        $maintenance = Maintenance::factory()->create();
        $url = "/api/v1/mantenimientos/{$maintenance->id}";
        $collection = $this->collectionUrl($maintenance->poultryHouse);

        // Acción: recorre lecturas y escrituras sin una sesión.
        $this->getJson($collection)->assertUnauthorized();
        $this->getJson($collection.'/ultimo')->assertUnauthorized();
        $this->getJson($url)->assertUnauthorized();
        $this->postJson($collection, [])->assertUnauthorized();
        $this->patchJson($url, [])->assertUnauthorized();
        $this->postJson($url.'/cancelacion', [])->assertUnauthorized();
    }

    // Flujo: verifica la separación entre permisos de lectura y gestión.
    public function test_http_endpoints_enforce_functional_permissions(): void
    {
        // Preparación: crea un mantenimiento y un usuario sin permisos.
        $maintenance = Maintenance::factory()->create();
        $url = "/api/v1/mantenimientos/{$maintenance->id}";
        $collection = $this->collectionUrl($maintenance->poultryHouse);
        Sanctum::actingAs(User::factory()->create(), ['*']);

        // Acción: rechaza lecturas sin permiso y escrituras con permiso sólo de lectura.
        $this->getJson($collection)->assertForbidden();
        $this->getJson($collection.'/ultimo')->assertForbidden();
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs($this->userWithPermissions(['poultry-houses.view']), ['*']);
        $this->postJson($collection, [])->assertForbidden();
        $this->patchJson($url, [])->assertForbidden();
        $this->postJson($url.'/cancelacion', [])->assertForbidden();
    }

    // Flujo: comprueba la matriz de autorización y deniega usuarios desactivados.
    #[DataProvider('permissionMatrix')]
    public function test_policy_matrix(array $permissions, bool $admin, bool $read, bool $write): void
    {
        // Preparación: crea el usuario y las capacidades de la fila.
        $actor = $this->userWithPermissions($permissions);
        $maintenance = Maintenance::factory()->create();
        if ($admin) {
            $actor->assignRole(Role::findOrCreate('admin', 'web'));
        }

        // Acción: consulta cada operación autorizable.
        $gate = Gate::forUser($actor);
        $this->assertSame($read, $gate->allows('viewAny', Maintenance::class));
        $this->assertSame($read, $gate->allows('view', $maintenance));
        $this->assertSame($write, $gate->allows('create', Maintenance::class));
        $this->assertSame($write, $gate->allows('update', $maintenance));
        $this->assertSame($write, $gate->allows('cancel', $maintenance));

        // Verificación: una baja del usuario elimina el acceso incluso para administradores.
        $actor->delete();
        $this->assertFalse($gate->allows('view', $maintenance));
        $this->assertFalse($gate->allows('create', Maintenance::class));
    }

    /** @return array<string, array{list<string>, bool, bool, bool}> */
    public static function permissionMatrix(): array
    {
        return [
            'sin permisos' => [[], false, false, false],
            'lectura' => [['poultry-houses.view'], false, true, false],
            'gestión' => [['poultry-houses.manage'], false, false, true],
            'administrador' => [[], true, true, true],
        ];
    }

    // Flujo: falla auditoría y verifica rollback de cada operación de escritura.
    #[DataProvider('writeOperations')]
    public function test_audit_failure_rolls_back_business_change(string $operation): void
    {
        // Preparación: crea el contexto y simula una falla del almacenamiento de auditoría.
        $maintenance = Maintenance::factory()->create();
        $actor = $this->userWithPermissions(['poultry-houses.manage']);
        Sanctum::actingAs($actor, ['*']);
        $before = $maintenance->refresh()->getAttributes();
        $this->app->instance(AuditRecorder::class, new class implements AuditRecorder
        {
            public function record(AuditEntryData $entry): void
            {
                throw new RuntimeException('Audit storage failed.');
            }
        });

        // Acción: ejecuta la escritura cuyo registro de auditoría fallará.
        $response = match ($operation) {
            'create' => $this->withHeader('Idempotency-Key', Str::uuid()->toString())
                ->postJson($this->collectionUrl($maintenance->poultryHouse), $this->payload($actor)),
            'update' => $this->patchJson("/api/v1/mantenimientos/{$maintenance->id}", [
                'version' => 1, 'reason' => 'Corrección', 'cost_amount' => '0.00',
            ]),
            'cancel' => $this->postJson("/api/v1/mantenimientos/{$maintenance->id}/cancelacion", [
                'version' => 1, 'reason' => 'Duplicado',
            ]),
        };
        $response->assertInternalServerError();

        // Verificación: la transacción no guarda cambios sin auditoría.
        $this->assertSame($before, $maintenance->fresh()->getAttributes());
        $this->assertDatabaseCount('maintenances', 1);
        $this->assertDatabaseCount('activity_log', 0);
    }

    /** @return array<string, array{string}> */
    public static function writeOperations(): array
    {
        return ['alta' => ['create'], 'corrección' => ['update'], 'cancelación' => ['cancel']];
    }

    // Flujo: rechaza referencias inexistentes y evita reasignar un mantenimiento a otro galpón.
    public function test_missing_resources_return_404_and_house_cannot_be_reassigned(): void
    {
        // Preparación: autentica un usuario con lectura y gestión.
        $maintenance = Maintenance::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['poultry-houses.view', 'poultry-houses.manage']), ['*']);

        // Acción: consulta recursos inexistentes y envía una modificación del propietario.
        $this->getJson('/api/v1/galpones/999999/mantenimientos')->assertNotFound();
        $this->getJson('/api/v1/mantenimientos/999999')->assertNotFound();
        $this->patchJson("/api/v1/mantenimientos/{$maintenance->id}", [
            'version' => 1, 'reason' => 'Cambio', 'description' => 'Nuevo texto', 'poultry_house_id' => 999999,
        ])->assertUnprocessable()->assertJsonValidationErrors('poultry_house_id');
    }

    // Flujo: PostgreSQL impide costos negativos, estados imposibles y pérdida de referencias históricas.
    #[DataProvider('databaseViolations')]
    public function test_database_constraints_preserve_history(string $violation): void
    {
        // Preparación: crea un mantenimiento con referencias persistidas.
        $maintenance = Maintenance::factory()->create();

        try {
            // Acción: intenta una escritura inválida fuera de los endpoints.
            DB::transaction(function () use ($maintenance, $violation): void {
                match ($violation) {
                    'cost' => DB::table('maintenances')->where('id', $maintenance->id)->update(['cost_amount' => '-1']),
                    'status' => DB::table('maintenances')->where('id', $maintenance->id)->update(['status' => 'scheduled']),
                    'cancel' => DB::table('maintenances')->where('id', $maintenance->id)->update(['status' => 'cancelled']),
                    'house' => DB::table('poultry_houses')->where('id', $maintenance->poultry_house_id)->delete(),
                    'responsible' => DB::table('users')->where('id', $maintenance->responsible_user_id)->delete(),
                };
            });
            $this->fail('La restricción debería impedir la operación.');
        } catch (QueryException $exception) {
            // Verificación: confirma la integridad de PostgreSQL y la conservación del hecho.
            $this->assertContains($exception->errorInfo[0], ['23001', '23503', '23514']);
        }

        $this->assertModelExists($maintenance);
    }

    /** @return array<string, array{string}> */
    public static function databaseViolations(): array
    {
        return ['costo' => ['cost'], 'estado' => ['status'], 'cancelación incompleta' => ['cancel'], 'galpón' => ['house'], 'responsable' => ['responsible']];
    }

    /** @return array{maintenance_date: string, description: string, cost_amount: string, cost_currency: string, responsible_user_id: int} */
    private function payload(User $responsible): array
    {
        return [
            'maintenance_date' => '2026-01-15',
            'description' => 'Reparación de bebederos',
            'cost_amount' => '1250.50',
            'cost_currency' => 'UYU',
            'responsible_user_id' => $responsible->id,
        ];
    }

    private function collectionUrl(PoultryHouse $house): string
    {
        return "/api/v1/galpones/{$house->id}/mantenimientos";
    }

    /** @param list<string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user;
    }
}
