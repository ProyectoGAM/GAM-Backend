<?php

namespace Tests\Feature\Lots;

use App\Events\Lots\WeighingRecorded;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\IdentityAndAccess\AuthSession;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use App\Models\SharedDevice;
use App\Models\User;
use App\Services\IdentityAndAccess\PinHasher;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpFoundation\Cookie;

final class WeighingEndpointTest extends LotsTestCase
{
    /** Crea la admisión histórica con un actor explícito para pruebas sin guard simulado. */
    private function flockWithHistoryAs(int $quantity, int $createdBy): Flock
    {
        $flock = $this->flock($quantity);
        FlockMovement::factory()->create([
            'destination_flock_id' => $flock->id,
            'quantity' => $quantity,
            'occurred_at' => $flock->established_at,
            'created_by' => $createdBy,
            'after' => [$flock->public_id => [
                'public_id' => $flock->public_id,
                'poultry_house_id' => $flock->poultry_house_id,
                'production_unit_id' => $flock->production_unit_id,
                'current_quantity' => $quantity,
                'entry_date' => $flock->entry_date->format('Y-m-d'),
            ]],
        ]);

        return $flock;
    }

    /** @return array{0: User, 1: SharedDevice, 2: string} */
    private function sharedFixture(): array
    {
        $this->seed(IdentityPermissionSeeder::class);
        config(['identity.pin.pepper' => 'test-only-temporary-value']);

        $employee = User::factory()->create();
        $employee->assignRole('employee');
        $employee->forceFill([
            'pin_hash' => app(PinHasher::class)->hash('0007'),
            'pin_enabled' => true,
            'pin_pepper_version' => config('identity.pin.pepper_version'),
            'pin_daily_window_started_at' => now(),
        ])->save();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $secret = Str::random(64);
        $device = SharedDevice::query()->create([
            'name' => 'Test tablet',
            'credential_hash' => hash('sha256', $secret),
            'credential_expires_at' => now()->addYear(),
            'enrolled_by' => $admin->getKey(),
            'enrolled_at' => now(),
        ]);

        $this->assertSame(0, AuthSession::query()->count());

        return [$employee, $device, $device->getKey().'|'.$secret];
    }

    /** Limpia el guard y los headers persistentes del cliente de pruebas. */
    private function clearSharedRequestContext(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
    }

    /** Crea un lote con la admisión histórica en la semana solicitada. */
    private function flockAtWeekWithHistory(int $quantity, int $week): Flock
    {
        $entry = now(config('lots.timezone'))->subWeeks($week - 1)->startOfDay();
        $flock = $this->flock($quantity);
        $flock->forceFill([
            'entry_date' => $entry->toDateString(),
            'established_at' => $entry->utc(),
        ])->save();
        FlockMovement::factory()->create([
            'destination_flock_id' => $flock->id,
            'quantity' => $quantity,
            'occurred_at' => $entry->utc(),
            'created_by' => auth()->id(),
            'after' => [$flock->public_id => [
                'public_id' => $flock->public_id,
                'poultry_house_id' => $flock->poultry_house_id,
                'production_unit_id' => $flock->production_unit_id,
                'current_quantity' => $quantity,
                'entry_date' => $flock->entry_date->format('Y-m-d'),
            ]],
        ]);

        return $flock->fresh();
    }

    // Flujo: configura rangos globales y conserva el control optimista de versión.
    public function test_settings_can_be_created_and_updated_with_version(): void
    {
        // Preparación: autentica sólo con el permiso administrativo de pesajes.
        $this->signIn(['weighing-settings.manage']);

        // Request: crea la configuración y luego la actualiza con su versión.
        $this->command('PUT', '/configuracion-pesajes', [
            'adult_from_week' => 18,
            'unit' => 'kg',
            'chick_min_weight' => '0.0100',
            'chick_max_weight' => '0.1000',
            'adult_min_weight' => '0.1000',
            'adult_max_weight' => '3.0000',
        ])->assertOk()->assertJsonPath('data.settings.version', 1);
        $this->command('PUT', '/configuracion-pesajes', [
            'version' => 1,
            'adult_from_week' => 20,
            'unit' => 'g',
            'chick_min_weight' => '10.0',
            'chick_max_weight' => '100.0',
            'adult_min_weight' => '100.0',
            'adult_max_weight' => '3000.0',
        ])->assertOk()->assertJsonPath('data.settings.version', 2);

        // Verificación: persiste gramos normalizados y la nueva versión.
        $this->assertDatabaseHas('weighing_reference_settings', [
            'version' => 2,
            'chick_min_weight_g' => '10.0',
            'adult_max_weight_g' => '3000.0',
        ]);
    }

    // Flujo: aplica límites inclusivos, fija la etapa en el umbral y conserva el snapshot histórico.
    public function test_reference_snapshot_uses_inclusive_bounds_and_adult_threshold(): void
    {
        // Preparación: configura rangos estrechos para distinguir pollitos y adultos.
        $this->signIn(['weighings.view', 'weighings.manage', 'weighing-settings.manage']);
        $this->command('PUT', '/configuracion-pesajes', [
            'adult_from_week' => 18,
            'unit' => 'g',
            'chick_min_weight' => '10.0',
            'chick_max_weight' => '20.0',
            'adult_min_weight' => '100.0',
            'adult_max_weight' => '200.0',
        ])->assertOk();
        $chick = $this->flockWithHistory(20);

        // Request: registra los límites exactos de la etapa de pollito.
        $chickResponse = $this->command('POST', '/pesajes', [
            'flock_id' => $chick->public_id,
            'mode' => 'individual',
            'unit' => 'g',
            'measurements' => [['weight' => '10.0'], ['weight' => '20.0']],
        ])->assertCreated();
        $chickId = $chickResponse->json('data.weighing.id');

        // Verificación: guarda la referencia, etapa y rango con límites inclusivos.
        $chickResponse
            ->assertJsonPath('data.weighing.reference.version', 1)
            ->assertJsonPath('data.weighing.stage', 'chick')
            ->assertJsonPath('data.weighing.expected_range.min_weight_g', '10.0')
            ->assertJsonPath('data.weighing.expected_range.max_weight_g', '20.0')
            ->assertJsonPath('data.weighing.outside_expected_range', false);

        // Request: actualiza la configuración global para crear una nueva versión.
        $this->command('PUT', '/configuracion-pesajes', [
            'version' => 1,
            'adult_from_week' => 18,
            'unit' => 'g',
            'chick_min_weight' => '11.0',
            'chick_max_weight' => '19.0',
            'adult_min_weight' => '101.0',
            'adult_max_weight' => '199.0',
        ])->assertOk()->assertJsonPath('data.settings.version', 2);

        // Consulta: verifica que la captura histórica no cambia con la configuración nueva.
        $this->getJson('/api/v1/pesajes/'.$chickId)
            ->assertOk()
            ->assertJsonPath('data.reference.version', 1)
            ->assertJsonPath('data.stage', 'chick')
            ->assertJsonPath('data.expected_range.min_weight_g', '10.0')
            ->assertJsonPath('data.expected_range.max_weight_g', '20.0')
            ->assertJsonPath('data.outside_expected_range', false);

        // Preparación: crea un lote cuya edad calculada es exactamente la semana adulta configurada.
        $adult = $this->flockAtWeekWithHistory(20, 18);

        // Request: registra los límites exactos en la semana adulta inclusiva.
        $this->command('POST', '/pesajes', [
            'flock_id' => $adult->public_id,
            'mode' => 'individual',
            'unit' => 'g',
            'measurements' => [['weight' => '101.0'], ['weight' => '199.0']],
        ])->assertCreated()
            ->assertJsonPath('data.weighing.reference.version', 2)
            ->assertJsonPath('data.weighing.stage', 'adult')
            ->assertJsonPath('data.weighing.expected_range.min_weight_g', '101.0')
            ->assertJsonPath('data.weighing.expected_range.max_weight_g', '199.0')
            ->assertJsonPath('data.weighing.outside_expected_range', false);
    }

    // Flujo: una corrección posterior incorpora la configuración vigente a un pesaje sin referencia.
    public function test_correction_adopts_current_reference_when_original_was_unconfigured(): void
    {
        // Preparación: registra primero un pesaje sin configuración global.
        $this->signIn(['weighings.view', 'weighings.manage', 'weighing-settings.manage']);
        $flock = $this->flockWithHistory(20);
        $created = $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id,
            'mode' => 'individual',
            'unit' => 'g',
            'measurements' => [['weight' => '20.0']],
        ])->assertCreated()->assertJsonPath('data.warnings.0.code', 'WEIGHING_REFERENCE_UNAVAILABLE');
        $id = $created->json('data.weighing.id');

        // Request: crea la configuración que estará vigente al corregir.
        $this->command('PUT', '/configuracion-pesajes', [
            'adult_from_week' => 18,
            'unit' => 'g',
            'chick_min_weight' => '10.0',
            'chick_max_weight' => '100.0',
            'adult_min_weight' => '100.0',
            'adult_max_weight' => '3000.0',
        ])->assertOk();

        // Request: corrige sólo las notas y deja que el servidor conserve las mediciones.
        $correction = $this->command('PATCH', '/pesajes/'.$id, [
            'version' => 1,
            'correction_reason' => 'Se incorporó la referencia vigente',
            'notes' => 'Referencia aplicada en corrección',
        ])->assertOk();

        // Verificación: adopta la referencia sin reemplazar el peso ni generar advertencia.
        $correction
            ->assertJsonPath('data.weighing.reference.version', 1)
            ->assertJsonPath('data.weighing.stage', 'chick')
            ->assertJsonPath('data.weighing.notes', 'Referencia aplicada en corrección')
            ->assertJsonMissingPath('data.warnings.0');
        $this->assertDatabaseHas('weighings', [
            'public_id' => $id,
            'reference_version' => 1,
            'version' => 2,
        ]);
    }

    // Flujo: usa el snapshot histórico del movimiento para validar población al momento del pesaje.
    public function test_historical_projection_rejects_population_above_snapshot(): void
    {
        // Preparación: reduce históricamente el lote a cinco aves después de su admisión.
        $this->signIn(['weighings.manage']);
        $flock = $this->flockWithHistory(20);
        $movementAt = now(config('lots.timezone'))->subDays(2)->startOfHour();
        FlockMovement::factory()->create([
            'source_flock_id' => $flock->id,
            'destination_flock_id' => null,
            'type' => 'mortality',
            'quantity' => 15,
            'before' => [$flock->public_id => [
                'public_id' => $flock->public_id,
                'poultry_house_id' => $flock->poultry_house_id,
                'production_unit_id' => $flock->production_unit_id,
                'current_quantity' => 20,
                'entry_date' => $flock->entry_date->format('Y-m-d'),
            ]],
            'after' => [$flock->public_id => [
                'public_id' => $flock->public_id,
                'poultry_house_id' => $flock->poultry_house_id,
                'production_unit_id' => $flock->production_unit_id,
                'current_quantity' => 5,
                'entry_date' => $flock->entry_date->format('Y-m-d'),
            ]],
            'occurred_at' => $movementAt->utc(),
            'created_by' => auth()->id(),
        ]);

        // Request: intenta representar seis aves cuando el snapshot histórico sólo conserva cinco.
        $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id,
            'mode' => 'individual',
            'unit' => 'g',
            'occurred_at' => $movementAt->addHour()->toIso8601String(),
            'measurements' => array_fill(0, 6, ['weight' => '20.0']),
        ])->assertConflict()
            ->assertJsonPath('code', 'WEIGHING_POPULATION_EXCEEDED')
            ->assertJsonPath('meta.population', 5)
            ->assertJsonPath('meta.represented_bird_count', 6);

        // Verificación: la proyección histórica evita cualquier persistencia parcial.
        $this->assertDatabaseCount('weighings', 0);
        $this->assertDatabaseCount('weighing_measurements', 0);
    }

    // Flujo: bloquea nuevos pesajes de un lote finalizado y permite corregir una captura histórica válida.
    public function test_finished_flock_rejects_new_historical_weighing_but_allows_existing_correction(): void
    {
        // Preparación: registra una captura antes de finalizar el lote.
        $this->signIn(['weighings.view', 'weighings.manage', 'flocks.finalize']);
        $flock = $this->flockWithHistory(20);
        $historicalAt = $flock->established_at->addHour();
        $created = $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id,
            'mode' => 'individual',
            'unit' => 'g',
            'occurred_at' => $historicalAt->toIso8601String(),
            'measurements' => [['weight' => '20.0']],
        ])->assertCreated();
        $id = $created->json('data.weighing.id');

        // Mutación: finaliza el lote con el egreso de sus aves.
        $this->command('POST', '/flocks/'.$flock->public_id.'/finalization', [
            'version' => 1,
            'reason' => 'Fin del ciclo productivo',
        ])->assertOk();

        // Request: rechaza un pesaje nuevo aunque su fecha pertenezca a la vida histórica del lote.
        $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id,
            'mode' => 'individual',
            'unit' => 'g',
            'occurred_at' => $historicalAt->toIso8601String(),
            'measurements' => [['weight' => '21.0']],
        ])->assertConflict()
            ->assertJsonPath('code', 'WEIGHING_FLOCK_LIFECYCLE_CONFLICT');

        // Request: corrige la captura existente usando la proyección histórica con aves.
        $this->command('PATCH', '/pesajes/'.$id, [
            'version' => 1,
            'correction_reason' => 'Corrección posterior al cierre',
            'notes' => 'Captura histórica corregida',
            'occurred_at' => $historicalAt->toIso8601String(),
        ])->assertOk()
            ->assertJsonPath('data.weighing.version', 2)
            ->assertJsonPath('data.weighing.notes', 'Captura histórica corregida');
    }

    // Flujo: exige confirmación sin generar efectos parciales y permite el replay confirmado.
    public function test_outlier_confirmation_has_no_side_effects_before_confirmed_replay(): void
    {
        // Preparación: configura el rango de pollitos y un lote con snapshot de admisión.
        $this->signIn(['weighings.view', 'weighings.manage', 'weighing-settings.manage']);
        $this->command('PUT', '/configuracion-pesajes', [
            'adult_from_week' => 18, 'unit' => 'g',
            'chick_min_weight' => '10.0', 'chick_max_weight' => '100.0',
            'adult_min_weight' => '100.0', 'adult_max_weight' => '3000.0',
        ])->assertOk();
        $flock = $this->flockWithHistory(10);
        $key = (string) Str::uuid();

        // Request: intenta guardar una fila fuera de rango sin confirmación.
        $payload = ['flock_id' => $flock->public_id, 'mode' => 'individual', 'unit' => 'g', 'measurements' => [['weight' => '221.0']]];
        $this->command('POST', '/pesajes', $payload, $key)->assertConflict()->assertJsonPath('code', 'WEIGHING_OUT_OF_RANGE_CONFIRMATION_REQUIRED');

        // Verificación: el primer conflicto no crea pesaje, medición, operación ni auditoría.
        $this->assertDatabaseCount('weighings', 0);
        $this->assertDatabaseCount('weighing_measurements', 0);
        $this->assertDatabaseCount('flock_operations', 1);
        $this->assertDatabaseMissing('activity_log', ['event' => 'weighing_recorded']);

        // Request: confirma con la misma clave y recibe una operación persistida.
        $created = $this->command('POST', '/pesajes', [...$payload, 'confirm_out_of_range' => true], $key)->assertCreated();

        // Verificación: guarda la marca outlier y el replay confirmado devuelve el mismo resultado.
        $created->assertJsonPath('data.weighing.outside_expected_range', true);
        $this->assertDatabaseCount('weighings', 1);
        $this->assertDatabaseHas('weighing_measurements', ['outside_expected_range' => true]);
    }

    // Flujo: registra un pesaje grupal con promedio ponderado y unidad capturada.
    public function test_group_weighing_persists_weighted_average_and_measurements(): void
    {
        // Preparación: autentica al gestor y crea el snapshot histórico del lote.
        $this->signIn(['weighings.view', 'weighings.manage']);
        $flock = $this->flockWithHistory(20);

        // Request: registra dos tandas en kilogramos.
        $response = $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id,
            'mode' => 'group',
            'unit' => 'kg',
            'measurements' => [
                ['total_weight' => '0.5000', 'bird_count' => 5],
                ['total_weight' => '0.8400', 'bird_count' => 8],
            ],
        ])->assertCreated();

        // Verificación: total y promedio quedan en gramos exactos y seis decimales.
        $response->assertJsonPath('data.weighing.total_weight_g', '1340.0');
        $response->assertJsonPath('data.weighing.average_weight_g', '103.076923');
        $this->assertDatabaseHas('weighings', ['mode' => 'group', 'represented_bird_count' => 13]);
        $this->assertDatabaseCount('weighing_measurements', 2);
    }

    // Flujo: devuelve la ausencia de configuración como advertencia sin impedir el registro.
    public function test_weighing_without_reference_settings_returns_warning(): void
    {
        // Preparación: autentica al gestor y confirma que todavía no hay configuración global.
        $this->signIn(['weighings.view', 'weighings.manage', 'weighing-settings.manage']);
        $flock = $this->flockWithHistory(20);

        // Consulta: solicita la configuración inexistente.
        $this->getJson('/api/v1/configuracion-pesajes')
            ->assertOk()
            ->assertJsonPath('data', null);

        // Request: registra un pesaje sin configuración de referencia.
        $response = $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id,
            'mode' => 'individual',
            'unit' => 'g',
            'measurements' => [['weight' => '20.0']],
        ])->assertCreated();

        // Verificación: persiste la captura y expone la advertencia contractual.
        $response->assertJsonPath('data.warnings.0.code', 'WEIGHING_REFERENCE_UNAVAILABLE');
        $this->assertDatabaseCount('weighings', 1);
        $this->assertDatabaseCount('weighing_measurements', 1);
    }

    // Flujo: repite una operación idéntica sin duplicar el pesaje ni su auditoría.
    public function test_idempotent_replay_returns_same_operation_and_rejects_payload_change(): void
    {
        // Preparación: autentica al gestor y construye una solicitud con clave estable.
        $this->signIn(['weighings.manage']);
        $flock = $this->flockWithHistory(20);
        $payload = [
            'flock_id' => $flock->public_id,
            'mode' => 'individual',
            'unit' => 'g',
            'measurements' => [['weight' => '20.0']],
        ];
        $key = (string) Str::uuid();

        // Request: ejecuta el primer registro idempotente.
        $first = $this->command('POST', '/pesajes', $payload, $key)->assertCreated();
        $operationId = $first->json('data.operation_id');

        // Request: repite exactamente el mismo comando.
        $replay = $this->command('POST', '/pesajes', $payload, $key)->assertCreated();

        // Verificación: devuelve la misma operación sin duplicar estado ni auditoría.
        $this->assertSame($operationId, $replay->json('data.operation_id'));
        $this->assertSame($first->json('data.weighing.id'), $replay->json('data.weighing.id'));
        $this->assertDatabaseCount('weighings', 1);
        $this->assertDatabaseCount('weighing_measurements', 1);
        $this->assertDatabaseCount('activity_log', 1);

        // Request: impide reutilizar la clave con un payload distinto.
        $this->command('POST', '/pesajes', [...$payload, 'measurements' => [['weight' => '21.0']]], $key)
            ->assertConflict();

        // Verificación: el payload conflictivo tampoco genera una segunda operación.
        $this->assertDatabaseCount('weighings', 1);
        $this->assertDatabaseCount('weighing_measurements', 1);
        $this->assertDatabaseCount('activity_log', 1);
    }

    // Flujo: aplica las reglas condicionales de modo, campos desconocidos y precisión decimal.
    public function test_weighing_validation_enforces_mode_fields_unknown_keys_and_precision(): void
    {
        // Preparación: autentica al gestor y crea población suficiente para todas las solicitudes.
        $this->signIn(['weighings.manage']);
        $flock = $this->flockWithHistory(20);

        // Request: rechaza campos de grupo cuando el modo es individual.
        $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id,
            'mode' => 'individual',
            'unit' => 'g',
            'measurements' => [['weight' => '20.0', 'total_weight' => '20.0', 'bird_count' => 1]],
        ])->assertUnprocessable();

        // Request: rechaza campos individuales cuando el modo es grupal.
        $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id,
            'mode' => 'group',
            'unit' => 'g',
            'measurements' => [['weight' => '20.0', 'bird_count' => 1]],
        ])->assertUnprocessable();

        // Request: rechaza una clave desconocida en el nivel superior.
        $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id,
            'mode' => 'individual',
            'unit' => 'g',
            'unexpected' => true,
            'measurements' => [['weight' => '20.0']],
        ])->assertUnprocessable();

        // Request: rechaza más de un decimal en gramos.
        $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id,
            'mode' => 'individual',
            'unit' => 'g',
            'measurements' => [['weight' => '20.11']],
        ])->assertUnprocessable();

        // Request: acepta un decimal en gramos.
        $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id,
            'mode' => 'individual',
            'unit' => 'g',
            'measurements' => [['weight' => '20.1']],
        ])->assertCreated();

        // Request: rechaza más de cuatro decimales en kilogramos.
        $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id,
            'mode' => 'individual',
            'unit' => 'kg',
            'measurements' => [['weight' => '0.02001']],
        ])->assertUnprocessable();

        // Request: acepta cuatro decimales en kilogramos.
        $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id,
            'mode' => 'individual',
            'unit' => 'kg',
            'measurements' => [['weight' => '0.0200']],
        ])->assertCreated();
    }

    // Flujo: respeta el límite contractual de mil mediciones y rechaza la siguiente.
    public function test_weighing_accepts_1000_measurements_and_rejects_1001(): void
    {
        // Preparación: autentica al gestor y crea un lote con población suficiente.
        $this->signIn(['weighings.manage']);
        $flock = $this->flockWithHistory(1001);
        $measurements = array_fill(0, 1000, ['weight' => '1.0']);

        // Request: registra exactamente mil mediciones.
        $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id,
            'mode' => 'individual',
            'unit' => 'g',
            'measurements' => $measurements,
        ])->assertCreated();

        // Verificación: persiste todas las mediciones aceptadas.
        $this->assertDatabaseCount('weighings', 1);
        $this->assertDatabaseCount('weighing_measurements', 1000);

        // Request: rechaza mil una mediciones en la validación de entrada.
        $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id,
            'mode' => 'individual',
            'unit' => 'g',
            'measurements' => array_fill(0, 1001, ['weight' => '1.0']),
        ])->assertUnprocessable();

        // Verificación: el rechazo no agrega pesajes ni mediciones.
        $this->assertDatabaseCount('weighings', 1);
        $this->assertDatabaseCount('weighing_measurements', 1000);
    }

    // Flujo: decide un outlier grupal por el promedio de cada tanda y conserva el rechazo sin efectos.
    public function test_group_outlier_uses_batch_average_and_requires_confirmation(): void
    {
        // Preparación: configura el rango de pollitos y crea un lote de veinte aves.
        $this->signIn(['weighings.view', 'weighings.manage', 'weighing-settings.manage']);
        $this->command('PUT', '/configuracion-pesajes', [
            'adult_from_week' => 18,
            'unit' => 'g',
            'chick_min_weight' => '10.0',
            'chick_max_weight' => '100.0',
            'adult_min_weight' => '100.0',
            'adult_max_weight' => '3000.0',
        ])->assertOk();
        $flock = $this->flockWithHistory(20);
        $payload = [
            'flock_id' => $flock->public_id,
            'mode' => 'group',
            'unit' => 'g',
            'measurements' => [
                ['total_weight' => '50.0', 'bird_count' => 5],
                ['total_weight' => '600.0', 'bird_count' => 5],
            ],
        ];

        // Request: intenta guardar una tanda cuyo promedio de ciento veinte gramos queda fuera del rango.
        $this->command('POST', '/pesajes', $payload)
            ->assertConflict()
            ->assertJsonPath('code', 'WEIGHING_OUT_OF_RANGE_CONFIRMATION_REQUIRED')
            ->assertJsonPath('meta.rows.0.value', '120.000000');

        // Verificación: el conflicto no crea pesaje, mediciones, auditoría ni operación adicional.
        $this->assertDatabaseCount('weighings', 0);
        $this->assertDatabaseCount('weighing_measurements', 0);
        $this->assertDatabaseCount('flock_operations', 1);
        $this->assertDatabaseMissing('activity_log', ['event' => 'weighing_recorded']);

        // Request: confirma el mismo grupo y persiste la marca de fuera de rango.
        $confirmed = $this->command('POST', '/pesajes', [...$payload, 'confirm_out_of_range' => true])->assertCreated();

        // Verificación: la tanda conserva su promedio y la captura queda marcada.
        $confirmed->assertJsonPath('data.weighing.outside_expected_range', true);
        $confirmed->assertJsonPath('data.weighing.represented_bird_count', 10);
        $this->assertDatabaseHas('weighing_measurements', [
            'position' => 2,
            'average_weight_g' => '120.000000',
            'outside_expected_range' => true,
        ]);
    }

    // Flujo: exige el contexto vigente de dispositivo y sesión para usuarios compartidos nativos.
    public function test_shared_user_weighing_requires_current_device_and_session_context(): void
    {
        // Preparación: replica el fixture de dispositivo compartido y habilita lectura de pesajes.
        [$employee, , $credential] = $this->sharedFixture();
        $employee->givePermissionTo(Permission::findOrCreate('weighings.view', 'web'));
        $this->flockWithHistoryAs(20, $employee->getKey());

        // Request: inicia la primera sesión nativa compartida con el PIN del empleado.
        $this->clearSharedRequestContext();
        $firstLogin = $this->withHeader('X-Shared-Device-Token', $credential)
            ->postJson('/api/v1/shared-device/login-pin', [
                'user_id' => $employee->getKey(),
                'pin' => '0007',
            ])->assertOk();
        $firstToken = $firstLogin->json('access_token');
        $firstSession = $firstLogin->json('session.id');

        // Request: permite leer pesajes con los tres encabezados vigentes.
        $this->clearSharedRequestContext();
        $this->withHeaders([
            'Authorization' => 'Bearer '.$firstToken,
            'X-Shared-Device-Token' => $credential,
            'X-GAM-Session' => $firstSession,
        ])->getJson('/api/v1/pesajes')->assertOk();

        // Request: reemplaza la sesión iniciando sesión nuevamente en el mismo dispositivo.
        $this->clearSharedRequestContext();
        $secondLogin = $this->withHeader('X-Shared-Device-Token', $credential)
            ->postJson('/api/v1/shared-device/login-pin', [
                'user_id' => $employee->getKey(),
                'pin' => '0007',
            ])->assertOk();
        $secondToken = $secondLogin->json('access_token');
        $secondSession = $secondLogin->json('session.id');

        // Request: rechaza el token y la sesión anteriores después del reemplazo.
        $this->clearSharedRequestContext();
        $this->withHeaders([
            'Authorization' => 'Bearer '.$firstToken,
            'X-Shared-Device-Token' => $credential,
            'X-GAM-Session' => $firstSession,
        ])->getJson('/api/v1/pesajes')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'SESSION_EXPIRED');

        // Request: permite el segundo contexto vigente.
        $this->clearSharedRequestContext();
        $this->withHeaders([
            'Authorization' => 'Bearer '.$secondToken,
            'X-Shared-Device-Token' => $credential,
            'X-GAM-Session' => $secondSession,
        ])->getJson('/api/v1/pesajes')->assertOk();

        // Request: expira el contexto nuevo cuando falta el encabezado de sesión.
        $this->clearSharedRequestContext();
        $this->withHeaders([
            'Authorization' => 'Bearer '.$secondToken,
            'X-Shared-Device-Token' => $credential,
            'X-GAM-Session' => '',
        ])->getJson('/api/v1/pesajes')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'SESSION_EXPIRED');
    }

    // Flujo: una sesión web compartida sólo acepta la cookie de dispositivo y no el encabezado nativo.
    public function test_shared_web_weighing_requires_cookie_device_transport(): void
    {
        // Preparación: crea un empleado y dispositivo compartido para el login web real.
        [$employee, , $credential] = $this->sharedFixture();
        $employee->givePermissionTo(Permission::findOrCreate('weighings.view', 'web'));

        // Request: inicia una sesión web real y conserva la cookie de sesión emitida.
        $login = $this->withCookie('gam_shared_device', $credential)
            ->withCredentials()
            ->postJson('/api/v1/shared-device/web/login-pin', [
                'user_id' => $employee->getKey(),
                'pin' => '0007',
            ])->assertOk();
        $session = AuthSession::query()
            ->where('user_id', $employee->getKey())
            ->where('transport', 'cookie')
            ->latest('issued_at')
            ->firstOrFail();
        $sessionCookie = collect($login->headers->getCookies())
            ->first(fn (Cookie $cookie): bool => $cookie->getName() === config('session.cookie'));
        $this->assertNotNull($sessionCookie);

        // Request: permite la consulta cuando usa la cookie web y la sesión emitida.
        $this->clearSharedRequestContext();
        $this->withUnencryptedCookie(config('session.cookie'), $sessionCookie->getValue())
            ->withCookie('gam_shared_device', $credential)
            ->withHeaders([
                'Origin' => 'http://localhost',
                'X-GAM-Session' => $session->getKey(),
            ])
            ->getJson('/api/v1/pesajes')
            ->assertOk();

        // Request: rechaza la mezcla de cookie web y credencial nativa.
        $this->clearSharedRequestContext();
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'X-GAM-Session' => $session->getKey(),
            'X-Shared-Device-Token' => $credential,
        ])
            ->getJson('/api/v1/pesajes')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'SHARED_DEVICE_UNAUTHORIZED');
    }

    // Flujo: revierte un registro de pesaje cuando la auditoría síncrona falla.
    public function test_audit_failure_rolls_back_weighing_recording(): void
    {
        // Preparación: instala una falla controlada en el contrato de auditoría.
        $this->signIn(['weighings.manage']);
        $flock = $this->flockWithHistory(20);
        Event::fake([WeighingRecorded::class]);
        $this->mock(AuditRecorder::class)
            ->shouldReceive('record')
            ->once()
            ->andThrow(new RuntimeException('Fallo controlado de auditoría'));

        // Request: registra un pesaje y fuerza el rollback transaccional.
        $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id,
            'mode' => 'individual',
            'unit' => 'g',
            'measurements' => [['weight' => '20.0']],
        ])->assertStatus(500);

        // Verificación: no quedan pesaje, medición, operación, auditoría ni evento.
        $this->assertDatabaseCount('weighings', 0);
        $this->assertDatabaseCount('weighing_measurements', 0);
        $this->assertDatabaseCount('flock_operations', 0);
        $this->assertDatabaseMissing('activity_log', ['event' => 'weighing_recorded']);
        Event::assertNotDispatched(WeighingRecorded::class);
    }

    // Flujo: corrige lote, modo, fecha y mediciones con versión y motivo.
    public function test_correction_replaces_measurements_and_appends_audit(): void
    {
        // Preparación: registra un pesaje individual sobre un lote histórico.
        $this->signIn(['weighings.view', 'weighings.manage']);
        $flock = $this->flockWithHistory(20);
        $created = $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id,
            'mode' => 'individual',
            'unit' => 'g',
            'measurements' => [['weight' => '20.0'], ['weight' => '22.0']],
        ])->assertCreated();
        $id = $created->json('data.weighing.id');

        // Request: corrige el modo completo a dos tandas grupales.
        $correction = $this->command('PATCH', '/pesajes/'.$id, [
            'version' => 1,
            'correction_reason' => 'Corrección de libreta',
            'mode' => 'group',
            'unit' => 'g',
            'measurements' => [
                ['total_weight' => '40.0', 'bird_count' => 2],
                ['total_weight' => '60.0', 'bird_count' => 3],
            ],
        ]);
        $correction
            ->assertOk()
            ->assertJsonPath('data.weighing.version', 2)
            ->assertJsonPath('data.weighing.represented_bird_count', 5)
            ->assertJsonPath('data.weighing.total_weight_g', '100.0');

        // Verificación: conserva una sola fila vigente y dos auditorías append-only.
        $this->assertDatabaseHas('weighings', ['public_id' => $id, 'mode' => 'group', 'version' => 2]);
        $this->assertDatabaseCount('weighing_measurements', 2);
        $this->assertDatabaseHas('activity_log', ['event' => 'weighing_recorded']);
        $this->assertDatabaseHas('activity_log', ['event' => 'weighing_corrected']);

        // Verificación: conserva motivo y snapshots allowlisted completos de la corrección.
        $audit = AuditEntry::query()->where('event', 'weighing_corrected')->latest('id')->firstOrFail();
        $changes = $audit->attribute_changes->toArray();
        $properties = $audit->properties->toArray();
        $this->assertSame('Corrección de libreta', $properties['reason']);
        $this->assertSame('individual', $changes['old']['mode']);
        $this->assertSame('group', $changes['new']['mode']);
        $this->assertSame('20.0', $changes['old']['measurements'][0]['weight_g']);
        $this->assertSame('40.0', $changes['new']['measurements'][0]['total_weight_g']);
        $this->assertSame(2, $changes['new']['measurements'][0]['bird_count']);
        $this->assertArrayHasKey('total_weight_g', $changes['old']);
        $this->assertArrayHasKey('measurements', $changes['old']);
        $this->assertArrayHasKey('total_weight_g', $changes['new']);
        $this->assertArrayHasKey('measurements', $changes['new']);
        $auditJson = json_encode([$properties, $changes], JSON_THROW_ON_ERROR);
        foreach (['password', 'pin', 'token', 'secret', 'idempotency_key'] as $sensitiveKey) {
            $this->assertStringNotContainsString($sensitiveKey, Str::lower($auditJson));
        }
    }

    // Flujo: permite correcciones parciales y protege la versión y el motivo obligatorios.
    public function test_partial_correction_requires_reason_and_current_version(): void
    {
        // Preparación: registra una captura individual para corregir únicamente sus notas.
        $this->signIn(['weighings.manage']);
        $flock = $this->flockWithHistory(20);
        $created = $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id,
            'mode' => 'individual',
            'unit' => 'g',
            'measurements' => [['weight' => '20.0'], ['weight' => '22.0']],
        ])->assertCreated();
        $id = $created->json('data.weighing.id');

        // Request: aplica una corrección parcial conservando modo y mediciones.
        $this->command('PATCH', '/pesajes/'.$id, [
            'version' => 1,
            'correction_reason' => 'Aclaración de notas',
            'notes' => 'Registro parcial',
        ])->assertOk()
            ->assertJsonPath('data.weighing.version', 2)
            ->assertJsonPath('data.weighing.notes', 'Registro parcial');

        // Verificación: la corrección parcial no reemplaza las dos mediciones vigentes.
        $this->assertDatabaseCount('weighing_measurements', 2);

        // Request: rechaza una corrección sin versión.
        $this->command('PATCH', '/pesajes/'.$id, [
            'correction_reason' => 'Falta versión',
            'notes' => 'No debe persistir',
        ])->assertUnprocessable();

        // Request: rechaza una corrección sin motivo.
        $this->command('PATCH', '/pesajes/'.$id, [
            'version' => 2,
            'notes' => 'Falta motivo',
        ])->assertUnprocessable();

        // Request: rechaza la versión obsoleta después de la corrección exitosa.
        $this->command('PATCH', '/pesajes/'.$id, [
            'version' => 1,
            'correction_reason' => 'Versión obsoleta',
            'notes' => 'No debe persistir',
        ])->assertConflict()
            ->assertJsonPath('code', 'WEIGHING_VERSION_CONFLICT');
    }

    // Flujo: expone detalle, listado, evolución y distribución individual.
    public function test_read_endpoints_return_ordered_points_and_distribution(): void
    {
        // Preparación: crea dos capturas con fechas distintas para un mismo lote.
        $this->signIn(['weighings.view', 'weighings.manage']);
        $flock = $this->flockWithHistory(20);
        $earlier = $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id, 'mode' => 'individual', 'unit' => 'g',
            'occurred_at' => now()->subDays(2)->toIso8601String(),
            'measurements' => [['weight' => '10.0'], ['weight' => '20.0'], ['weight' => '30.0']],
        ])->assertCreated();
        $latest = $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id, 'mode' => 'individual', 'unit' => 'g',
            'occurred_at' => now()->subDay()->toIso8601String(),
            'measurements' => [['weight' => '15.0'], ['weight' => '25.0'], ['weight' => '35.0']],
        ])->assertCreated();
        $id = $latest->json('data.weighing.id');

        // Consulta: verifica que el listado desciende por fecha y la evolución asciende.
        $list = $this->getJson('/api/v1/pesajes')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame($id, $list->json('data.0.id'));
        $this->assertSame($earlier->json('data.weighing.id'), $list->json('data.1.id'));
        $evolution = $this->getJson('/api/v1/pesajes/evolucion?flock_id='.$flock->public_id.'&unit=kg')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->assertSame($earlier->json('data.weighing.id'), $evolution->json('data.0.id'));
        $this->assertSame($id, $evolution->json('data.1.id'));

        // Consulta: obtiene el detalle y la curva gaussiana de la captura individual.
        $this->getJson('/api/v1/pesajes/'.$id)->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson('/api/v1/pesajes/'.$id.'/distribucion')->assertOk()->assertJsonCount(81, 'data.curve');
    }

    // Flujo: distingue la distribución grupal no disponible de una muestra individual insuficiente.
    public function test_distribution_reports_group_unavailable_and_single_sample_reason(): void
    {
        // Preparación: registra una captura grupal y otra individual de una sola ave.
        $this->signIn(['weighings.view', 'weighings.manage']);
        $flock = $this->flockWithHistory(20);
        $group = $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id,
            'mode' => 'group',
            'unit' => 'g',
            'measurements' => [['total_weight' => '40.0', 'bird_count' => 2]],
        ])->assertCreated();
        $individual = $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id,
            'mode' => 'individual',
            'unit' => 'g',
            'measurements' => [['weight' => '20.0']],
        ])->assertCreated();

        // Consulta: informa que la distribución de un pesaje grupal no está disponible.
        $this->getJson('/api/v1/pesajes/'.$group->json('data.weighing.id').'/distribucion')
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'group_mode');

        // Consulta: informa muestra insuficiente para una única medición individual.
        $this->getJson('/api/v1/pesajes/'.$individual->json('data.weighing.id').'/distribucion')
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.n', 1)
            ->assertJsonPath('data.reason', 'insufficient_sample')
            ->assertJsonPath('data.curve', null);
    }

    // Flujo: exige autenticación y separa lectura, gestión de pesajes y configuración.
    public function test_authentication_and_weighing_permissions_are_separated(): void
    {
        // Request: rechaza la consulta de pesajes sin autenticación.
        $this->getJson('/api/v1/pesajes')->assertUnauthorized();

        // Preparación: autentica sólo con permiso de lectura y crea un lote de prueba.
        $this->signIn(['weighings.view']);
        $flock = $this->flockWithHistory(20);

        // Request: impide mutar pesajes cuando el usuario sólo puede leer.
        $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id,
            'mode' => 'individual',
            'unit' => 'g',
            'measurements' => [['weight' => '20.0']],
        ])->assertForbidden();

        // Verificación: la solicitud sin permiso no muta el almacenamiento.
        $this->assertDatabaseCount('weighings', 0);

        // Preparación: cambia a un usuario con gestión, sin permiso de lectura.
        $this->signIn(['weighings.manage']);

        // Request: impide leer pesajes cuando falta el permiso de lectura.
        $this->getJson('/api/v1/pesajes')->assertForbidden();
        $this->getJson('/api/v1/configuracion-pesajes')->assertForbidden();

        // Preparación: autentica sólo el permiso administrativo de configuración.
        $this->signIn(['weighing-settings.manage']);

        // Request: permite consultar configuración pero no pesajes.
        $this->getJson('/api/v1/configuracion-pesajes')->assertOk();
        $this->getJson('/api/v1/pesajes')->assertForbidden();
    }
}
