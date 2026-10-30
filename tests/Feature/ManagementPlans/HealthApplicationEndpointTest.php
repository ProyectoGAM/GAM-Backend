<?php

namespace Tests\Feature\ManagementPlans;

use App\Actions\ManagementPlans\RecordMedicineApplicationAction;
use App\Actions\ManagementPlans\RecordVaccinationApplicationAction;
use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\ManagementPlans\FlockPlanActivity;
use App\Models\ManagementPlans\MedicineStockBalance;
use App\Models\SuppliersAndCatalogs\Medicine;
use App\Models\SuppliersAndCatalogs\Vaccine;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\Feature\Lots\LotsTestCase;

final class HealthApplicationEndpointTest extends LotsTestCase
{
    // Flujo: registra manejos previstos con motivo omitido, nulo o informado, sin stock.
    #[TestWith(['medicine', 'medication', 'medicaciones', null])]
    #[TestWith(['vaccine', 'vaccination', 'vacunaciones', null])]
    #[TestWith(['medicine', 'medication', 'medicaciones', 'Control previsto'])]
    #[TestWith(['vaccine', 'vaccination', 'vacunaciones', 'Control previsto'])]
    public function test_planned_application_is_idempotent_without_quantity(string $kind, string $type, string $path, ?string $reason): void
    {
        // Preparación: vincula un catálogo concreto al plan vigente y conserva un saldo histórico.
        $this->signIn(['management-plans.view', 'management-plans.execute']);
        $catalog = $kind === 'medicine' ? Medicine::factory()->create() : Vaccine::factory()->create();
        $flock = $this->flockWithPlan(activities: [[
            'type' => $type, 'title' => 'Aplicación prevista', 'timing_kind' => 'week', 'start_week' => 1,
            'catalog_type' => $kind, 'catalog_id' => $catalog->id, 'catalog_snapshot' => ['id' => $catalog->public_id],
        ]]);
        $activity = FlockPlanActivity::query()->where('type', $type)->sole();
        $balance = MedicineStockBalance::factory()->create(['on_hand_quantity' => -7]);
        $payload = [$kind.'_id' => $catalog->public_id, 'plan_activity_id' => $activity->public_id,
            'occurred_at' => now()->subMinute()->toIso8601String()];
        if ($reason !== null) {
            $payload['reason'] = $reason;
        }
        $key = (string) Str::uuid();
        $root = $kind === 'medicine' ? 'medicine_application' : 'vaccination_application';

        // Request: registra y repite exactamente la misma aplicación sin duplicar efectos.
        $first = $this->command('POST', '/flocks/'.$flock->public_id.'/'.$path, $payload, $key)->assertCreated()
            ->assertJsonPath('data.'.$root.'.reason', $reason)
            ->assertJsonMissingPath('data.'.$root.'.quantity')->assertJsonMissingPath('data.stock_balance')
            ->assertJsonPath('data.warnings', []);
        $this->command('POST', '/flocks/'.$flock->public_id.'/'.$path, $payload, $key)->assertCreated()->assertExactJson($first->json());
        $this->assertDatabaseHas($kind === 'medicine' ? 'medicine_applications' : 'vaccination_applications', [
            'public_id' => $first->json('data.'.$root.'.id'), 'reason' => $reason, 'inventory_movement_id' => null,
        ]);
        $this->assertDatabaseCount($kind === 'medicine' ? 'medicine_applications' : 'vaccination_applications', 1);
        $this->assertDatabaseCount('medicine_stock_movements', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame(-7, $balance->fresh()->on_hand_quantity);
        $this->assertSame(1, AuditEntry::query()->where('event', $root.'_recorded')->count());

        // Consulta: el historial conserva el motivo y el vínculo al plan.
        $this->getJson('/api/v1/flocks/'.$flock->public_id.'/manejos?type='.$type)->assertOk()
            ->assertJsonPath('data.0.module_data.reason', $reason)->assertJsonPath('data.0.outside_plan', false);

        // Request: la misma clave con un motivo diferente devuelve conflicto.
        $this->command('POST', '/flocks/'.$flock->public_id.'/'.$path, [...$payload, 'reason' => 'Otro motivo'], $key)->assertConflict();

        // Request: también admite motivo explícitamente nulo en una aplicación prevista nueva.
        $this->command('POST', '/flocks/'.$flock->public_id.'/'.$path, [...$payload, 'reason' => null])->assertCreated()
            ->assertJsonPath('data.'.$root.'.reason', null);
    }

    // Flujo: exige un motivo real fuera del plan sin permitir que las notas lo sustituyan.
    #[TestWith(['medicine', 'medicaciones'])]
    #[TestWith(['vaccine', 'vacunaciones'])]
    public function test_unplanned_application_requires_reason_and_preserves_it(string $kind, string $path): void
    {
        // Preparación: el lote no tiene ninguna actividad sanitaria vinculada.
        $this->signIn(['management-plans.view', 'management-plans.execute']);
        $flock = $this->flockWithPlan();
        $catalog = $kind === 'medicine' ? Medicine::factory()->create() : Vaccine::factory()->create();
        $payload = [$kind.'_id' => $catalog->public_id, 'occurred_at' => now()->subMinute()->toIso8601String(), 'notes' => 'Observaciones'];

        // Requests: motivo ausente, nulo y vacío no generan registros ni auditoría sanitaria.
        foreach ([$payload, [...$payload, 'reason' => null], [...$payload, 'reason' => '   ']] as $invalid) {
            $this->command('POST', '/flocks/'.$flock->public_id.'/'.$path, $invalid)->assertUnprocessable()
                ->assertJsonValidationErrors('reason')->assertJsonPath('errors.reason.0', 'Debes indicar un motivo cuando la aplicación no está prevista en el plan de manejo.');
        }
        $this->assertDatabaseCount($kind === 'medicine' ? 'medicine_applications' : 'vaccination_applications', 0);
        $this->assertSame(0, AuditEntry::query()->whereIn('event', ['medicine_application_recorded', 'vaccination_application_recorded'])->count());

        // Request: el motivo válido queda disponible tanto en la respuesta como en el historial.
        $this->command('POST', '/flocks/'.$flock->public_id.'/'.$path, [...$payload, 'reason' => '  Indicación veterinaria  '])->assertCreated();
        $this->getJson('/api/v1/flocks/'.$flock->public_id.'/manejos')->assertOk()
            ->assertJsonPath('data.0.module_data.reason', 'Indicación veterinaria')->assertJsonPath('data.0.outside_plan', true);
    }

    // Flujo: no se elude el motivo con actividades ajenas, de otro tipo, obsoletas o de otro catálogo.
    #[TestWith(['medicine', 'medication', 'medicaciones'])]
    #[TestWith(['vaccine', 'vaccination', 'vacunaciones'])]
    public function test_invalid_plan_links_do_not_allow_reason_omission(string $kind, string $type, string $path): void
    {
        // Preparación: arma el plan propio, otro lote y una referencia distinta del catálogo.
        $this->signIn(['management-plans.execute']);
        $catalog = $kind === 'medicine' ? Medicine::factory()->create() : Vaccine::factory()->create();
        $flock = $this->flockWithPlan(activities: [['type' => $type, 'title' => 'Otra ficha', 'timing_kind' => 'week', 'start_week' => 1,
            'catalog_type' => $kind, 'catalog_id' => 999, 'catalog_snapshot' => ['id' => (string) Str::ulid()]]]);
        $mismatched = FlockPlanActivity::query()->where('type', $type)->sole();
        $otherFlock = $this->flockWithPlan(activities: [['type' => $type, 'title' => 'Otro lote', 'timing_kind' => 'week', 'start_week' => 1]]);
        $foreign = FlockPlanActivity::query()->where('title', 'Otro lote')->sole();
        $wrongType = FlockPlanActivity::query()->where('flock_plan_revision_id', $mismatched->flock_plan_revision_id)->where('type', 'ration_change')->sole();

        // Requests: un vínculo inválido produce conflicto y no registra una aplicación.
        foreach ([$mismatched->public_id, $foreign->public_id, $wrongType->public_id, (string) Str::ulid()] as $activityId) {
            $this->command('POST', '/flocks/'.$flock->public_id.'/'.$path, [
                $kind.'_id' => $catalog->public_id, 'plan_activity_id' => $activityId, 'occurred_at' => now()->subMinute()->toIso8601String(),
            ])->assertConflict();
        }

        // Mutación y request: una revisión superada tampoco permite omitir el motivo.
        $foreign->revision->plan->forceFill(['current_revision' => 2])->save();
        $this->command('POST', '/flocks/'.$otherFlock->public_id.'/'.$path, [
            $kind.'_id' => $catalog->public_id, 'plan_activity_id' => $foreign->public_id, 'occurred_at' => now()->subMinute()->toIso8601String(),
        ])->assertConflict();
        $this->assertDatabaseCount($kind === 'medicine' ? 'medicine_applications' : 'vaccination_applications', 0);
    }

    // Flujo: las Actions también protegen el motivo ante llamadas internas sin FormRequest.
    #[TestWith(['medicine', []])]
    #[TestWith(['vaccine', []])]
    #[TestWith(['medicine', ['quantity' => 1, 'reason' => 'Tratamiento']])]
    #[TestWith(['vaccine', ['quantity' => 1, 'reason' => 'Tratamiento']])]
    #[TestWith(['medicine', ['stock_consumption' => ['quantity' => '1', 'stock_location_id' => 1], 'reason' => 'Tratamiento']])]
    #[TestWith(['vaccine', ['stock_consumption' => ['quantity' => '1', 'stock_location_id' => 1], 'reason' => 'Tratamiento']])]
    /** @param array<string, mixed> $extra */
    public function test_internal_application_enforces_reason_and_no_stock(string $kind, array $extra): void
    {
        // Preparación: usa un actor autorizado y un lote sin actividad sanitaria vinculada.
        $actor = $this->signIn(['management-plans.execute']);
        $flock = $this->flockWithPlan();
        $catalog = $kind === 'medicine' ? Medicine::factory()->create() : Vaccine::factory()->create();
        $action = $kind === 'medicine' ? RecordMedicineApplicationAction::class : RecordVaccinationApplicationAction::class;

        // Mutación: la llamada interna se revierte antes de persistir una aplicación sin motivo.
        try {
            app($action)->execute($flock, [$kind.'_id' => $catalog->public_id, 'idempotency_key' => (string) Str::uuid(),
                'occurred_at' => now()->subMinute()->toIso8601String(), ...$extra], $actor);
            $this->fail('La aplicación debe respetar el motivo y la ausencia de stock.');
        } catch (LotsConflict $exception) {
            $this->assertSame($extra === []
                ? 'Debes indicar un motivo cuando la aplicación no está prevista en el plan de manejo.'
                : 'Las aplicaciones sanitarias se registran sin cantidad aplicada ni consumo de stock.', $exception->getMessage());
        }
        $this->assertDatabaseCount($kind === 'medicine' ? 'medicine_applications' : 'vaccination_applications', 0);
    }

    // Flujo: rechaza cantidades y motivos excesivos en ambos tipos de aplicación.
    #[TestWith(['medicine', 'medicaciones'])]
    #[TestWith(['vaccine', 'vacunaciones'])]
    public function test_quantity_is_prohibited_and_reason_is_bounded(string $kind, string $path): void
    {
        // Preparación: usa un registro fuera de plan con motivo válido.
        $this->signIn(['management-plans.execute']);
        $flock = $this->flockWithPlan();
        $catalog = $kind === 'medicine' ? Medicine::factory()->create() : Vaccine::factory()->create();
        $payload = [$kind.'_id' => $catalog->public_id, 'reason' => 'Tratamiento', 'occurred_at' => now()->subMinute()->toIso8601String()];

        // Requests: no registra cantidades y limita la longitud del motivo.
        $this->command('POST', '/flocks/'.$flock->public_id.'/'.$path, [...$payload, 'quantity' => 1])->assertUnprocessable()
            ->assertJsonValidationErrors('quantity')->assertJsonPath('errors.quantity.0', 'La aplicación se registra sin cantidad aplicada.');
        $this->command('POST', '/flocks/'.$flock->public_id.'/'.$path, [...$payload, 'reason' => str_repeat('a', 501)])->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $this->assertDatabaseCount($kind === 'medicine' ? 'medicine_applications' : 'vaccination_applications', 0);
    }

    // Flujo: mantiene la autenticación y el permiso funcional para registrar sanidad.
    #[TestWith(['medicine', 'medicaciones'])]
    #[TestWith(['vaccine', 'vacunaciones'])]
    public function test_application_requires_authentication_and_execute_permission(string $kind, string $path): void
    {
        // Preparación: usa referencias existentes sin autenticar la primera petición.
        $flock = $this->flock();
        $catalog = $kind === 'medicine' ? Medicine::factory()->create() : Vaccine::factory()->create();
        $payload = [$kind.'_id' => $catalog->public_id, 'reason' => 'Tratamiento', 'occurred_at' => now()->subMinute()->toIso8601String()];

        // Requests: un visitante recibe 401 y un usuario sin permiso recibe 403.
        $this->command('POST', '/flocks/'.$flock->public_id.'/'.$path, $payload)->assertUnauthorized();
        $this->signIn(['management-plans.view']);
        $this->command('POST', '/flocks/'.$flock->public_id.'/'.$path, $payload)->assertForbidden();
        $this->assertDatabaseCount($kind === 'medicine' ? 'medicine_applications' : 'vaccination_applications', 0);
    }

    // Flujo: si falla la auditoría no queda aplicación y la misma clave permite reintentar.
    #[TestWith(['medicine', 'medicaciones'])]
    #[TestWith(['vaccine', 'vacunaciones'])]
    public function test_audit_failure_rolls_back_the_application(string $kind, string $path): void
    {
        // Preparación: falla únicamente el registro de auditoría de una aplicación válida.
        $this->signIn(['management-plans.execute']);
        $flock = $this->flockWithPlan();
        $catalog = $kind === 'medicine' ? Medicine::factory()->create() : Vaccine::factory()->create();
        $payload = [$kind.'_id' => $catalog->public_id, 'reason' => 'Tratamiento', 'occurred_at' => now()->subMinute()->toIso8601String()];
        $key = (string) Str::uuid();
        $recorder = app(AuditRecorder::class);
        Exceptions::fake();
        $this->mock(AuditRecorder::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Fallo de auditoría.'));

        // Request: la transacción revierte aplicación y clave junto con la auditoría.
        $this->command('POST', '/flocks/'.$flock->public_id.'/'.$path, $payload, $key)->assertInternalServerError();
        $this->assertDatabaseCount($kind === 'medicine' ? 'medicine_applications' : 'vaccination_applications', 0);
        $this->assertDatabaseMissing('flock_operations', ['idempotency_key' => $key]);
        Exceptions::assertReported(RuntimeException::class);

        // Request: vuelve a usar la misma clave una vez recuperada la auditoría.
        $this->app->instance(AuditRecorder::class, $recorder);
        $this->command('POST', '/flocks/'.$flock->public_id.'/'.$path, $payload, $key)->assertCreated();
        $this->assertDatabaseCount($kind === 'medicine' ? 'medicine_applications' : 'vaccination_applications', 1);
    }
}
