<?php

namespace Tests\Feature\Notifications;

use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\Deliveries\Delivery;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Notifications\AppNotification;
use App\Models\User;
use App\Models\Vehicles\Vehicle;
use App\Services\Deliveries\DeliveryEggAlerts;
use Carbon\CarbonImmutable;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AlertConfigurationEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    // El catálogo separa los ámbitos y refleja los umbrales guardados por la API existente.
    public function test_catalog_exposes_current_values_and_routes_for_each_scope(): void
    {
        // Preparación: crea una UP y cambia los días de vacunación mediante el contrato existente.
        $this->administrator();
        $unit = ProductionUnit::factory()->create();
        $unitPath = "/api/v1/unidades-productivas/{$unit->id}";
        $thresholds = ['bajo' => -10, 'medio' => -5, 'alto' => 0];
        $this->putJson($unitPath.'/umbrales-alertas/vaccination_due', $thresholds)->assertOk();

        // Consulta: obtiene las opciones efectivas, límites, rutas y valores en ambos ámbitos.
        $global = collect($this->getJson('/api/v1/configuracion-alertas')->assertOk()->json('data'))->keyBy('tipo');
        $local = collect($this->getJson($unitPath.'/configuracion-alertas')->assertOk()->json('data'))->keyBy('tipo');

        $this->assertCount(19, $global->keys()->merge($local->keys())->unique());
        $this->assertArrayNotHasKey('vaccination_due', $global->all());
        $this->assertArrayNotHasKey('vehicle_document_expiring', $local->all());
        $this->assertSame(30, $global['vehicle_document_expiring']['configuracion']['dias_anticipacion']);
        $this->assertSame($thresholds, $local['vaccination_due']['criterio_relacionado']['valores']);
        $this->assertSame($unitPath.'/umbrales-alertas/vaccination_due', $local['vaccination_due']['criterio_relacionado']['ruta']);
        $this->assertSame($unitPath.'/configuracion-alertas/vaccination_due', $local['vaccination_due']['ruta']);
    }

    // Rechaza solicitudes anónimas, escrituras sin permiso y parámetros ajenos al tipo.
    public function test_configuration_enforces_permissions_scope_and_validates_fields(): void
    {
        // Solicitud: el catálogo general requiere autenticación.
        $path = '/api/v1/configuracion-alertas';
        $this->getJson($path)->assertUnauthorized();

        // Preparación y solicitud: una cuenta personal sin permiso recibe 403.
        Sanctum::actingAs(User::factory()->create(), ['api:access']);
        $this->getJson($path)->assertForbidden();
        $this->patchJson($path.'/vehicle_document_expiring', ['habilitada' => false])->assertForbidden();

        // Preparación y solicitud: el administrador tampoco puede mezclar ámbitos ni enviar campos ajenos.
        $this->administrator();
        $unit = ProductionUnit::factory()->create();
        $unitPath = "/api/v1/unidades-productivas/{$unit->id}/configuracion-alertas";
        $this->patchJson($unitPath.'/vehicle_document_expiring', ['habilitada' => false])->assertNotFound();
        $this->patchJson($path.'/vaccination_due', ['habilitada' => false])->assertNotFound();
        $this->patchJson($path.'/inexistente', ['habilitada' => false])->assertNotFound();
        $this->patchJson($path.'/vehicle_document_expiring', [
            'dias_anticipacion' => -1, 'urgencia_fija' => 'critico', 'unidad_productiva_id' => $unit->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['dias_anticipacion', 'urgencia_fija', 'unidad_productiva_id']);

        // Consulta: ninguna solicitud rechazada persiste preferencias.
        $this->assertDatabaseCount('alert_configurations', 0);
    }

    // Cambiar la anticipación y la urgencia afecta la evaluación y el historial de la alerta.
    public function test_vehicle_evaluation_uses_the_saved_window_and_resolves_disabled_alerts(): void
    {
        // Preparación: programa una intervención fuera de la ventana predeterminada.
        $actor = $this->administrator();
        $this->travelTo(CarbonImmutable::parse('2026-11-06T12:00:00-03:00'));
        $vehicle = Vehicle::factory()->create();
        $this->postJson("/api/v1/vehiculos/{$vehicle->public_id}/mantenimientos", [
            'type' => 'preventive', 'description' => 'Cambio de aceite',
            'planned_at' => '2026-11-10T00:30', 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();
        $this->artisan('vehiculos:evaluar-intervenciones')->assertSuccessful();
        $this->assertDatabaseCount('app_notifications', 0);

        // Mutación: amplía la ventana y repite la misma configuración.
        $url = '/api/v1/configuracion-alertas/vehicle_maintenance_due';
        $changes = ['dias_anticipacion' => 4, 'urgencia_fija' => 'medio'];
        $this->patchJson($url, $changes)->assertOk()->assertJsonPath('data.configuracion.dias_anticipacion', 4);
        $this->patchJson($url, $changes)->assertOk();

        // Consulta: la nueva ventana publica el aviso con la urgencia solicitada y audita una vez.
        $this->artisan('vehiculos:evaluar-intervenciones')->assertSuccessful();
        $notice = AppNotification::query()->where('type', 'vehicle_maintenance_due')->sole();
        $this->assertSame('medio', $notice->urgency->value);
        $audit = AuditEntry::query()->where('event', 'alert_configuration_changed')->sole();
        $this->assertSame($actor->id, $audit->causer_id);
        $this->assertSame(3, $audit->attribute_changes['old']['dias_anticipacion']);
        $this->assertSame(4, $audit->attribute_changes['new']['dias_anticipacion']);

        // Mutación y consulta: deshabilita sin perder los demás valores y conserva el aviso resuelto.
        $this->patchJson($url, ['habilitada' => false])->assertOk()
            ->assertJsonPath('data.configuracion.dias_anticipacion', 4);
        $this->artisan('vehiculos:evaluar-intervenciones')->assertSuccessful();
        $this->assertNotNull($notice->refresh()->resolved_at);
        $this->assertDatabaseCount('app_notifications', 1);
    }

    // La publicación por evento aplica el umbral y la urgencia de su propia unidad.
    public function test_delivery_event_uses_its_unit_settings_and_keeps_existing_history(): void
    {
        // Preparación: configura el déficit mínimo de un reparto y deja otra unidad con sus valores iniciales.
        $this->administrator();
        $delivery = Delivery::factory()->create();
        $other = Delivery::factory()->create();
        $url = "/api/v1/unidades-productivas/{$delivery->production_unit_id}/configuracion-alertas/delivery_egg_balance_negative";
        $this->patchJson($url, ['deficit_minimo_huevos' => 3, 'urgencia_fija' => 'bajo'])->assertOk();
        $alerts = app(DeliveryEggAlerts::class);

        // Mutación y consulta: el mismo déficit sólo publica en la unidad sin el mínimo personalizado.
        $alerts->negativeDeliveryBalance($delivery, -1);
        $alerts->negativeDeliveryBalance($other, -1);
        $this->assertSame($other->public_id, AppNotification::query()->sole()->entity_id);

        // Mutación y consulta: alcanzar el mínimo publica con la urgencia configurada.
        $alerts->negativeDeliveryBalance($delivery, -3);
        $notice = AppNotification::query()->where('entity_id', $delivery->public_id)->sole();
        $this->assertSame('bajo', $notice->urgency->value);

        // Mutación y consulta: deshabilita los futuros avisos de esa unidad y conserva el ya publicado.
        $this->patchJson($url, ['habilitada' => false])->assertOk();
        $newDelivery = Delivery::factory()->create(['production_unit_id' => $delivery->production_unit_id]);
        $alerts->negativeDeliveryBalance($newDelivery, -5);
        $this->assertModelExists($notice);
        $this->assertDatabaseCount('app_notifications', 2);
    }

    // Un reparto con datos de varias unidades usa únicamente la configuración general.
    public function test_multi_unit_delivery_uses_general_settings(): void
    {
        // Preparación: crea un reparto con origen y devolución en unidades distintas.
        $this->administrator();
        $returnUnit = ProductionUnit::factory()->create();
        $delivery = Delivery::factory()->completed()->create(['return_production_unit_id' => $returnUnit->id, 'returned_quantity' => 4]);
        $local = "/api/v1/unidades-productivas/{$returnUnit->id}/configuracion-alertas/delivery_egg_surplus";
        $general = '/api/v1/configuracion-alertas/delivery_egg_surplus';
        $this->patchJson($local, ['habilitada' => false])->assertOk();
        $this->patchJson($general, ['sobrante_minimo_huevos' => 3, 'urgencia_fija' => 'alto'])->assertOk();

        // Mutación y consulta: la preferencia local no suprime el aviso de un reparto general.
        app(DeliveryEggAlerts::class)->surplus($delivery, $returnUnit->id, 4);
        $notice = AppNotification::query()->sole();
        $this->assertSame($delivery->public_id, $notice->entity_id);
        $this->assertSame('alto', $notice->urgency->value);
    }

    private function administrator(): User
    {
        $this->seed(IdentityPermissionSeeder::class);
        $user = User::factory()->create();
        $user->syncRoles(['admin']);
        Sanctum::actingAs($user, ['api:access']);

        return $user;
    }
}
