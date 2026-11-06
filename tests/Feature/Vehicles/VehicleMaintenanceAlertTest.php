<?php

namespace Tests\Feature\Vehicles;

use App\Models\Notifications\AppNotification;
use App\Models\User;
use App\Models\Vehicles\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class VehicleMaintenanceAlertTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: el aviso comienza tres días antes, escala al día siguiente y se resuelve al completar.
    public function test_dated_intervention_alert_escalates_after_the_local_due_day(): void
    {
        // Preparación: programa una intervención a primera hora con la fecha local que envía el formulario.
        $actor = $this->operator();
        $this->travelTo(CarbonImmutable::parse('2026-11-06T12:00:00-03:00'));
        $vehicle = Vehicle::factory()->create();
        $maintenance = $this->postJson("/api/v1/vehiculos/{$vehicle->public_id}/mantenimientos", [
            'type' => 'preventive', 'description' => 'Cambio de aceite',
            'planned_at' => '2026-11-10T00:30', 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->json('data.public_id');

        // Consulta: a cuatro días no hay aviso y a tres días hay uno solo, aunque se repita la evaluación.
        $this->artisan('vehiculos:evaluar-intervenciones')->assertSuccessful();
        $this->assertDatabaseCount('app_notifications', 0);
        $this->travelTo(CarbonImmutable::parse('2026-11-07T00:00:00-03:00'));
        $this->artisan('vehiculos:evaluar-intervenciones')->assertSuccessful();
        $this->artisan('vehiculos:evaluar-intervenciones')->assertSuccessful();
        $notice = AppNotification::query()->sole();
        $this->assertSame($actor->id, $notice->recipient_id);
        $this->assertStringContainsString('10/11/2026', $notice->message);
        $url = '/api/v1/notificaciones/'.$notice->public_id;
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.tipo', 'vehicle_maintenance_due')
            ->assertJsonPath('data.modulo', 'vehicles')
            ->assertJsonPath('data.urgencia', 'bajo')
            ->assertJsonPath('data.entidad', [
                'tipo' => 'vehicle_maintenance', 'id' => $maintenance, 'ruta' => '/administracion/vehiculos/'.$vehicle->public_id,
            ]);

        // Request y consulta: inicia la intervención, lee el aviso y comprueba que sigue bajo al terminar el día previsto.
        $this->patchJson($url.'/lectura')->assertOk();
        $this->postJson("/api/v1/vehiculos/mantenimientos/{$maintenance}/inicio")->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-11-10T23:59:59-03:00'));
        $this->artisan('vehiculos:evaluar-intervenciones')->assertSuccessful();
        $this->getJson($url)->assertOk()->assertJsonPath('data.urgencia', 'bajo');

        // Consulta: al comenzar el día siguiente, sube la urgencia del mismo aviso y vuelve a no leído.
        $this->travelTo(CarbonImmutable::parse('2026-11-11T00:00:00-03:00'));
        $this->artisan('vehiculos:evaluar-intervenciones')->assertSuccessful();
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.urgencia', 'alto')->assertJsonPath('data.leida_en', null);

        // Request y consulta: al completar la intervención, el aviso pasa al historial.
        $this->postJson("/api/v1/vehiculos/mantenimientos/{$maintenance}/finalizacion")->assertOk();
        $this->artisan('vehiculos:evaluar-intervenciones')->assertSuccessful();
        $this->getJson($url)->assertOk()->assertJsonPath('data.estado_resolucion', 'resuelto');
        $this->assertDatabaseCount('app_notifications', 1);
    }

    // Flujo: excluye intervenciones sin fecha y resuelve un aviso cuando se anula su intervención.
    public function test_only_dated_interventions_reach_authorized_personal_recipients_and_cancellation_resolves_them(): void
    {
        // Preparación: agrega un lector sin acceso a la bandeja y una cuenta PIN sin alcance sobre la flota general.
        $actor = $this->operator();
        config(['identity.pin.pepper' => 'vehicle-alert-test']);
        $pin = User::factory()->devicePin()->create();
        $noInbox = User::factory()->create();
        $read = Permission::findOrCreate('can_view_vehicle_maintenance', 'web');
        $inbox = Permission::findOrCreate('can_view_notifications', 'web');
        $pin->roles()->firstOrFail()->givePermissionTo($read, $inbox);
        $noInbox->roles()->firstOrFail()->givePermissionTo($read);
        $this->travelTo(CarbonImmutable::parse('2026-11-06T12:00:00-03:00'));
        $vehicle = Vehicle::factory()->create();
        $route = "/api/v1/vehiculos/{$vehicle->public_id}/mantenimientos";

        // Requests: programa un diagnóstico sin fecha y una intervención que ya venció.
        $this->postJson($route, [
            'type' => 'corrective', 'description' => 'Diagnóstico', 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();
        $maintenance = $this->postJson($route, [
            'type' => 'corrective', 'description' => 'Revisión de frenos',
            'planned_at' => '2026-11-05T18:00:00-03:00', 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->json('data.public_id');

        // Consulta: sólo el operador autorizado recibe el aviso de la intervención con fecha.
        $this->artisan('vehiculos:evaluar-intervenciones')->assertSuccessful();
        $notice = AppNotification::query()->sole();
        $this->assertSame($actor->id, $notice->recipient_id);
        $this->assertSame($maintenance, $notice->entity_id);
        $this->assertSame('alto', $notice->urgency->value);

        // Request y consulta: al anular la intervención ya no queda un aviso vigente.
        $this->postJson("/api/v1/vehiculos/mantenimientos/{$maintenance}/anulacion", ['reason' => 'Se canceló el turno.'])->assertOk();
        $this->artisan('vehiculos:evaluar-intervenciones')->assertSuccessful();
        $this->assertNotNull($notice->refresh()->resolved_at);
    }

    // Flujo: el plan avisa por kilometraje y cede a la intervención vinculada conservando la urgencia.
    public function test_preventive_odometer_alert_yields_to_a_linked_intervention_without_losing_urgency(): void
    {
        // Preparación: registra la primera lectura y un plan cuyo límite queda a 501 km.
        $this->operator(['can_manage_vehicle_readings']);
        $now = CarbonImmutable::parse('2026-11-06T12:00:00-03:00');
        $this->travelTo($now);
        $vehicle = Vehicle::factory()->create();
        $path = '/api/v1/vehiculos/'.$vehicle->public_id;
        $reading = fn (string $value, CarbonImmutable $at) => $this->postJson($path.'/lecturas', [
            'meter_type' => 'odometer', 'value' => $value, 'recorded_at' => $at->toIso8601String(),
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $reading('499', $now->subHours(3));
        $plan = $this->postJson($path.'/planes-mantenimiento', [
            'task' => 'Cambio de aceite', 'interval_km' => 1000, 'next_due_odometer_km' => 1000,
        ])->assertCreated()->json('data.public_id');

        // Consulta: el aviso aparece al entrar en la ventana de 500 km y escala al alcanzar el límite.
        $this->artisan('vehiculos:evaluar-intervenciones')->assertSuccessful();
        $this->assertDatabaseCount('app_notifications', 0);
        $reading('500', $now->subHours(2));
        $this->artisan('vehiculos:evaluar-intervenciones')->assertSuccessful();
        $notice = AppNotification::query()->sole();
        $this->assertSame('vehicle_maintenance_plan_due', $notice->type);
        $this->assertSame($plan, $notice->entity_id);
        $this->assertSame('bajo', $notice->urgency->value);
        $reading('1000', $now->subHour());
        $this->artisan('vehiculos:evaluar-intervenciones')->assertSuccessful();
        $this->assertSame('alto', $notice->refresh()->urgency->value);

        // Request y consulta: una intervención próxima asume el único aviso, que sigue siendo alto.
        $intervention = $this->postJson($path.'/mantenimientos', [
            'type' => 'preventive', 'description' => 'Servicio en taller',
            'planned_at' => '2026-11-08T10:00:00-03:00', 'idempotency_key' => (string) Str::uuid(),
            'items' => [['plan_id' => $plan]],
        ])->assertCreated()->json('data.public_id');
        $this->artisan('vehiculos:evaluar-intervenciones')->assertSuccessful();
        $current = AppNotification::query()->whereNull('resolved_at')->sole();
        $this->assertSame('vehicle_maintenance_due', $current->type);
        $this->assertSame($intervention, $current->entity_id);
        $this->assertSame('alto', $current->urgency->value);
        $this->assertNotNull($notice->refresh()->resolved_at);

        // Request y consulta: anular la intervención vuelve a mostrar el plan, que sigue pendiente.
        $this->postJson("/api/v1/vehiculos/mantenimientos/{$intervention}/anulacion", ['reason' => 'Reprogramación'])->assertOk();
        $this->artisan('vehiculos:evaluar-intervenciones')->assertSuccessful();
        $reopened = AppNotification::query()->whereNull('resolved_at')->sole();
        $this->assertSame($plan, $reopened->entity_id);
        $this->assertSame('alto', $reopened->urgency->value);
    }

    // Flujo: el límite calendario del plan se evalúa aunque falte una lectura de kilometraje.
    public function test_preventive_date_alert_works_without_an_odometer_reading(): void
    {
        // Preparación: programa un límite a treinta días y otro de kilometraje sin lectura.
        $this->operator();
        $this->travelTo(CarbonImmutable::parse('2026-11-06T12:00:00-03:00'));
        $vehicle = Vehicle::factory()->create();
        $this->postJson("/api/v1/vehiculos/{$vehicle->public_id}/planes-mantenimiento", [
            'task' => 'Revisión general', 'interval_days' => 90, 'interval_km' => 1000,
            'next_due_at' => '2026-12-06', 'next_due_odometer_km' => 1000,
        ])->assertCreated();

        // Consulta: el aviso normal sube a alto al llegar a la fecha límite del plan.
        $this->artisan('vehiculos:evaluar-intervenciones')->assertSuccessful();
        $notice = AppNotification::query()->sole();
        $this->assertSame('bajo', $notice->urgency->value);
        $this->travelTo(CarbonImmutable::parse('2026-12-06T00:00:00-03:00'));
        $this->artisan('vehiculos:evaluar-intervenciones')->assertSuccessful();
        $this->assertSame('alto', $notice->refresh()->urgency->value);
    }

    // Flujo: un documento avisa antes y después del vencimiento y se resuelve al cargar su renovación.
    public function test_vehicle_document_expiry_escalates_and_renewal_preserves_history(): void
    {
        // Preparación: carga un documento fechado y otro sin vencimiento.
        $actor = $this->operator(['can_view_vehicle_documents', 'can_manage_vehicle_documents']);
        $this->travelTo(CarbonImmutable::parse('2026-11-06T12:00:00-03:00'));
        Storage::fake('local');
        $vehicle = Vehicle::factory()->create();
        $path = '/api/v1/vehiculos/'.$vehicle->public_id;
        $upload = fn (string $title, ?string $expiry) => $this->post($path.'/documentos', [
            'title' => $title, 'document_type' => 'Seguro', 'expires_at' => $expiry,
            'file' => UploadedFile::fake()->create('seguro.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $original = $upload('Póliza', '2026-12-07');
        $upload('Comprobante', null);

        // Consulta: el aviso aparece a treinta días y sigue normal hasta terminar el día de vencimiento.
        $this->artisan('vehiculos:evaluar-documentos')->assertSuccessful();
        $this->assertDatabaseCount('app_notifications', 0);
        $this->travelTo(CarbonImmutable::parse('2026-11-07T00:00:00-03:00'));
        $this->artisan('vehiculos:evaluar-documentos')->assertSuccessful();
        $notice = AppNotification::query()->sole();
        $this->assertSame($actor->id, $notice->recipient_id);
        $this->assertSame($original, $notice->entity_id);
        $this->assertSame('vehicle_document_expiring', $notice->type);
        $this->assertSame('bajo', $notice->urgency->value);
        $this->travelTo(CarbonImmutable::parse('2026-12-07T23:59:59-03:00'));
        $this->artisan('vehiculos:evaluar-documentos')->assertSuccessful();
        $this->assertSame('bajo', $notice->refresh()->urgency->value);
        $this->travelTo(CarbonImmutable::parse('2026-12-08T00:00:00-03:00'));
        $this->artisan('vehiculos:evaluar-documentos')->assertSuccessful();
        $this->assertSame('alto', $notice->refresh()->urgency->value);

        // Request y consulta: otro título no renueva la póliza; el mismo título con fecha posterior sí.
        $upload('Otra póliza', '2027-12-07');
        $this->artisan('vehiculos:evaluar-documentos')->assertSuccessful();
        $this->assertNull($notice->refresh()->resolved_at);
        $upload('PÓLIZA', '2027-12-07');
        $this->artisan('vehiculos:evaluar-documentos')->assertSuccessful();
        $this->assertNotNull($notice->refresh()->resolved_at);
        $this->getJson($path)->assertOk()->assertJsonCount(4, 'documents')
            ->assertJsonPath('availability.warnings', []);
    }

    /** @param list<string> $extraPermissions */
    private function operator(array $extraPermissions = []): User
    {
        $user = User::factory()->create();
        foreach (['can_view_vehicle_maintenance', 'can_manage_vehicle_maintenance', 'can_view_notifications', ...$extraPermissions] as $permission) {
            $user->roles()->firstOrFail()->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Sanctum::actingAs($user, ['api:access']);

        return $user;
    }
}
