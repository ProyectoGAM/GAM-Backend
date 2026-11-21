<?php

namespace Tests\Feature\AuditAndTraceability;

use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\User;
use App\Models\Vehicles\Vehicle;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Lots\LotsTestCase;

final class BusinessAuditPresentationTest extends LotsTestCase
{
    // Flujo: el historial apunta a la sección y la anulación vigente de una intervención.
    public function test_vehicle_maintenance_link_and_action_use_public_ids_and_current_version(): void
    {
        // Preparación y alta: un operador autorizado agenda una intervención.
        $this->signIn(['can_view_audit', 'can_manage_vehicle_maintenance']);
        $vehicle = Vehicle::factory()->create();
        $maintenance = $this->command('POST', "/vehiculos/{$vehicle->public_id}/mantenimientos", [
            'type' => 'corrective', 'description' => 'Reparación de frenos',
        ])->assertCreated()->json('data.public_id');

        // Consulta: el listado abre Mantenimientos y el detalle identifica la intervención.
        $this->getJson('/api/v1/audit/entries?event=vehicle_maintenance_created')->assertOk()
            ->assertJsonPath('data.0.presentation.subject', [
                'label' => null, 'resource' => 'vehicle', 'id' => $vehicle->public_id, 'section' => 'maintenance',
            ]);
        $entry = $this->entry('vehicle_maintenance_created');
        $this->detail($entry)->assertJsonPath('data.presentation.actions.0', [
            'kind' => 'cancel_vehicle_maintenance', 'label' => 'Anular intervención', 'available' => true, 'reason' => null,
            'target' => ['resource' => 'vehicle_maintenance', 'id' => $maintenance, 'vehicle_id' => $vehicle->public_id, 'version' => 1],
        ]);

        // Mutación: la API de intervención acepta el ID y la versión de la presentación.
        $this->postJson("/api/v1/vehiculos/mantenimientos/{$maintenance}/anulacion", [
            'version' => 1, 'reason' => 'Intervención duplicada',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->detail($entry)->assertJsonPath('data.presentation.actions.0.available', false)
            ->assertJsonMissingPath('data.presentation.actions.0.target.version');
    }

    // Flujo: las referencias históricas de vehículos distinguen secciones y respetan permisos de lectura.
    public function test_vehicle_navigation_uses_each_historical_reference_without_falling_back_to_internal_ids(): void
    {
        // Preparación: conserva los formatos históricos empleados por las distintas acciones.
        $this->signIn(['can_view_audit', 'can_view_vehicles', 'can_view_vehicle_fuel', 'can_view_vehicle_maintenance', 'can_view_vehicle_documents']);
        $vehicle = Vehicle::factory()->create();
        $cases = [
            'vehicle_status_changed' => ['overview', ['subject_snapshot' => ['id' => $vehicle->public_id, 'plate' => $vehicle->plate]]],
            'vehicle_usage_started' => ['usages', ['vehicle_snapshot' => ['id' => $vehicle->public_id, 'plate' => $vehicle->plate]]],
            'vehicle_fuel_recorded' => ['fuel', ['vehicle_id' => $vehicle->public_id]],
            'vehicle_maintenance_plan_created' => ['maintenance_plans', ['vehicle_id' => $vehicle->public_id]],
            'vehicle_maintenance_document_uploaded' => ['maintenance', ['vehicle_id' => $vehicle->public_id]],
            'vehicle_document_uploaded' => ['documents', ['vehicle_id' => $vehicle->public_id]],
        ];
        foreach ($cases as $event => [$section, $properties]) {
            // Preparación y consulta: el listado proyecta la sección desde la instantánea de cada evento.
            AuditEntry::query()->create(['event' => $event, 'log_name' => 'vehicles', 'description' => 'Evento de prueba', 'properties' => $properties]);
            $this->getJson('/api/v1/audit/entries?event='.$event)->assertOk()
                ->assertJsonPath('data.0.presentation.subject.id', $vehicle->public_id)
                ->assertJsonPath('data.0.presentation.subject.section', $section);
        }

        // Consulta: una matrícula visible no habilita el acceso a documentos.
        $this->signIn(['can_view_audit', 'can_view_vehicles']);
        $this->detail($this->entry('vehicle_document_uploaded'))->assertJsonPath('data.presentation.subject.id', null);

        // Preparación y consulta: una entrada antigua con un ID interno queda sin enlace.
        $legacy = AuditEntry::query()->create([
            'event' => 'vehicle_usage_closed', 'log_name' => 'vehicles', 'description' => 'Uso cerrado',
            'properties' => ['vehicle_id' => $vehicle->id],
        ]);
        $this->detail($legacy)->assertJsonPath('data.presentation.subject.id', null);
    }

    // Flujo: una rectificación deshabilita la acción de la lectura sustituida y conserva la de la lectura vigente.
    public function test_reading_action_tracks_the_effective_reading_and_permissions(): void
    {
        // Preparación y mutación: registra una lectura con los permisos de flota.
        $this->signIn(['can_view_audit', 'can_view_vehicles', 'can_manage_vehicle_readings']);
        $vehicle = Vehicle::factory()->create();
        $path = "/vehiculos/{$vehicle->public_id}/lecturas";
        $reading = $this->command('POST', $path, ['value' => '1200.0'])->assertCreated()->json('data.public_id');
        $entry = $this->entry('vehicle_meter_reading_recorded');

        // Consulta: el detalle entrega los dos ULID que requiere el comando.
        $this->detail($entry)->assertJsonPath('data.presentation.subject.section', 'readings')
            ->assertJsonPath('data.presentation.actions.0.kind', 'correct_vehicle_reading')
            ->assertJsonPath('data.presentation.actions.0.available', true)
            ->assertJsonPath('data.presentation.actions.0.target', [
                'resource' => 'vehicle_meter_reading', 'id' => $reading, 'vehicle_id' => $vehicle->public_id,
            ]);

        // Mutación y consulta: corrige la lectura y vuelve a consultar ambos registros.
        $this->command('POST', $path, [
            'value' => '1201.0', 'event_type' => 'correction', 'corrects_reading_id' => $reading,
            'correction_reason' => 'Error de digitación',
        ])->assertCreated();
        $this->detail($entry)->assertJsonPath('data.presentation.actions.0.available', false);
        $correction = $this->entry('vehicle_meter_reading_corrected');
        $this->detail($correction)->assertJsonPath('data.presentation.actions.0.available', true);

        // Consulta: con lectura de flota se conserva el enlace, pero se deshabilita la rectificación.
        $this->signIn(['can_view_audit', 'can_view_vehicles']);
        $this->detail($correction)->assertJsonPath('data.presentation.subject.id', $vehicle->public_id)
            ->assertJsonPath('data.presentation.actions.0.available', false)
            ->assertJsonPath('data.presentation.actions.0.reason', 'No tienes permiso para rectificar lecturas.');

        // Consulta: sin acceso a flota el detalle omite el destino y la acción.
        $this->signIn(['can_view_audit']);
        $this->detail($correction)->assertJsonPath('data.presentation.subject.resource', null)
            ->assertJsonPath('data.presentation.actions', []);
    }

    // Flujo: enlaza ficha y cuenta corriente, y ofrece una única compensación de un pago.
    public function test_customer_payment_link_and_reversal_follow_account_permissions(): void
    {
        // Preparación: crea un cliente y registra un pago con un administrador.
        $this->seed(IdentityPermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);
        Sanctum::actingAs($admin, ['api:access']);
        $customer = $this->command('POST', '/clientes', ['name' => 'Cliente de prueba'])->assertCreated()->json('data.id');
        $payment = $this->command('POST', "/clientes/{$customer}/pagos", [
            'amount' => '10.500', 'payment_method' => 'cash',
        ])->assertOk()->json('data.0.id');
        $entry = $this->entry('customer_account_payment');

        // Consulta: el historial distingue las secciones y entrega el movimiento para enfocarlo.
        $this->detail($this->entry('customer_created'))->assertJsonPath('data.presentation.subject', [
            'label' => 'Cliente de prueba', 'resource' => 'customer', 'id' => $customer, 'section' => 'profile',
        ]);
        $this->detail($entry)->assertJsonPath('data.presentation.subject', [
            'label' => 'Cliente de prueba', 'resource' => 'customer', 'id' => $customer, 'section' => 'account', 'record_id' => $payment,
        ])->assertJsonPath('data.presentation.actions.0.kind', 'reverse_customer_movement')
            ->assertJsonPath('data.presentation.actions.0.available', true)
            ->assertJsonPath('data.presentation.actions.0.target', [
                'resource' => 'customer_account_movement', 'id' => $payment, 'customer_id' => $customer,
            ]);

        // Consulta: se puede leer una cuenta sin adquirir la facultad de revertir.
        $this->signIn(['can_view_audit', 'can_view_customer_accounts']);
        $this->detail($entry)->assertJsonPath('data.presentation.subject.id', $customer)
            ->assertJsonPath('data.presentation.actions.0.available', false);

        // Mutación y consulta: compensa el pago y confirma que la compensación no ofrece otra reversión.
        Sanctum::actingAs($admin, ['api:access']);
        $this->command('POST', "/clientes/{$customer}/movimientos/{$payment}/reversion", ['reason' => 'Pago duplicado'])
            ->assertOk()->assertJsonPath('data.0.reverses_movement_id', $payment);
        $this->detail($entry)->assertJsonPath('data.presentation.actions.0.reason', 'El movimiento ya tiene una reversión.');
        $this->detail($this->entry('customer_account_reversal'))->assertJsonPath('data.presentation.actions', []);
    }

    // Flujo: Manejos incluye las razas históricas y permite abrir el catálogo con el registro seleccionado.
    public function test_breed_history_links_to_catalog_without_offering_an_action(): void
    {
        // Preparación y mutación: registra una raza a través del catálogo existente.
        $this->signIn(['can_view_audit', 'can_view_breeds', 'can_manage_breeds']);
        $breed = $this->command('POST', '/breeds', ['name' => 'Raza de prueba'])->assertCreated()->json('data.catalog.id');
        $this->command('POST', '/breeds', ['name' => 'Otra raza'])->assertCreated();
        $this->command('PATCH', '/breeds/'.$breed, ['name' => 'Raza renombrada', 'version' => 1])->assertOk();
        $entry = AuditEntry::query()->where('event', 'breed_created')->where('subject_id', $breed)->sole();
        $this->assertSame('lots', $entry->log_name);

        // Consulta: Manejos devuelve el registro histórico con el ID del catálogo.
        $this->getJson('/api/v1/audit/entries?log_name=management_plans&event=breed_updated')->assertOk()
            ->assertJsonPath('data.0.log_name', 'management_plans')
            ->assertJsonPath('data.0.presentation.subject', [
                'label' => 'Raza renombrada', 'resource' => 'breed', 'id' => $breed, 'section' => 'catalog',
            ]);
        $this->getJson('/api/v1/audit/entries?log_name=lots&event=breed_created')->assertOk()->assertJsonCount(0, 'data');
        $this->detail($entry)->assertJsonPath('data.presentation.subject.id', $breed)->assertJsonPath('data.presentation.actions', []);

        // Consulta: el enlace resuelve el registro exacto con su nombre vigente, aun si cambió desde la auditoría.
        $this->getJson('/api/v1/breeds?id='.$breed)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $breed)->assertJsonPath('data.0.name', 'Raza renombrada');
        $this->getJson('/api/v1/breeds?id=0')->assertUnprocessable()->assertJsonValidationErrors('id');
    }

    private function entry(string $event): AuditEntry
    {
        return AuditEntry::query()->where('event', $event)->sole();
    }

    private function detail(AuditEntry $entry): TestResponse
    {
        return $this->getJson('/api/v1/auditoria/entradas/'.$entry->getKey())->assertOk();
    }
}
