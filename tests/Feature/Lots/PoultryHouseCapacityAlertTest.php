<?php

namespace Tests\Feature\Lots;

use App\Enums\Notifications\NotificationUrgency;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Lots\Flock;
use App\Models\Lots\WeighingReferenceSettings;
use App\Models\Notifications\AppNotification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Permission;

final class PoultryHouseCapacityAlertTest extends LotsTestCase
{
    // Flujo: el aviso escala al llegar a la adultez y se resuelve al eliminar el exceso de aves.
    public function test_over_capacity_alert_escalates_and_resolves_after_a_split(): void
    {
        // Preparación: el lote entra el 1 de octubre y llega a la sexta semana el 5 de noviembre.
        $entry = CarbonImmutable::parse('2026-10-01 00:00:00', config('lots.timezone'));
        $this->travelTo($entry->addDays(27));
        $actor = $this->signIn(['can_view_poultry_houses', 'can_view_notifications', 'can_redistribute_flocks']);
        WeighingReferenceSettings::factory()->create(['adult_from_week' => 6]);
        $source = PoultryHouse::factory()->create(['bird_capacity' => 100]);
        $destination = PoultryHouse::factory()->create(['production_unit_id' => $source->production_unit_id, 'bird_capacity' => 100]);
        $flock = Flock::factory()->create([
            'poultry_house_id' => $source->id,
            'entry_date' => $entry->toDateString(),
            'established_at' => $entry,
            'initial_quantity' => 120,
            'current_quantity' => 120,
        ]);
        $this->attachPublishedPlan($flock, $actor, $this->createPublishedPlanSelection($actor));

        // Consulta: con ocho días de anticipación no hay aviso; al faltar siete días aparece una sola vez.
        $this->artisan('galpones:evaluar-capacidad')->assertSuccessful();
        $this->assertDatabaseCount('app_notifications', 0);
        $this->travelTo($entry->addDays(28));
        $this->artisan('galpones:evaluar-capacidad')->assertSuccessful();
        $this->artisan('galpones:evaluar-capacidad')->assertSuccessful();
        $notice = AppNotification::query()->sole();
        $noticeUrl = '/api/v1/notificaciones/'.$notice->public_id;
        $this->getJson($noticeUrl)->assertOk()
            ->assertJsonPath('data.tipo', 'poultry_house_over_capacity')
            ->assertJsonPath('data.modulo', 'farm_structure')
            ->assertJsonPath('data.urgencia', 'medio')
            ->assertJsonPath('data.entidad', [
                'tipo' => 'poultry_house',
                'id' => (string) $source->id,
                'ruta' => "/administracion/unidades-productivas/{$source->production_unit_id}/galpon/{$source->id}",
            ]);

        // Request y consulta: marca el aviso como leído y confirma que se vuelve a mostrar al escalar.
        $this->patchJson($noticeUrl.'/lectura')->assertOk();
        $this->travelTo($entry->addDays(35)->subSecond());
        $this->artisan('galpones:evaluar-capacidad')->assertSuccessful();
        $this->assertSame(NotificationUrgency::Medium, $notice->refresh()->urgency);
        $this->travelTo($entry->addDays(35));
        $this->artisan('galpones:evaluar-capacidad')->assertSuccessful();
        $this->getJson($noticeUrl)->assertOk()
            ->assertJsonPath('data.urgencia', 'alto')
            ->assertJsonPath('data.leida_en', null);

        // Request: el primer fraccionamiento baja la ocupación a 110 y el exceso mantiene el aviso vigente.
        $split = $this->command('POST', "/flocks/{$flock->public_id}/redistributions", [
            'version' => 1, 'quantity' => 10, 'destination_poultry_house_id' => $destination->id,
            'destination_code' => 'FRACCION-PRUEBA',
        ])->assertCreated();
        $this->getJson($noticeUrl)->assertOk()->assertJsonPath('data.estado_resolucion', 'no_resuelto');
        $this->assertStringContainsString('10 aves', $notice->refresh()->message);

        // Request: el segundo fraccionamiento deja 100 aves y resuelve el aviso al confirmar la operación.
        $movement = $this->command('POST', "/flocks/{$flock->public_id}/redistributions", [
            'version' => 2, 'quantity' => 10, 'destination_flock_id' => $split->json('data.destination_flock.id'),
            'destination_version' => 1,
        ])->assertCreated();
        $this->getJson($noticeUrl)->assertOk()->assertJsonPath('data.estado_resolucion', 'resuelto');
        $this->assertSame(100, $flock->refresh()->current_quantity);
        $this->assertSame(100, $source->refresh()->bird_capacity);
        $this->assertDatabaseCount('app_notifications', 1);

        // Request: al revertir el segundo movimiento reaparece el exceso como un nuevo aviso alto.
        $this->command('POST', '/redistributions/'.$movement->json('data.movement.id').'/reversals', [
            'version' => 3, 'destination_version' => 2, 'reason' => 'Fraccionamiento revertido.',
        ])->assertOk();
        $reopened = AppNotification::query()->whereNull('resolved_at')->sole();
        $this->assertNotSame($notice->id, $reopened->id);
        $this->assertSame(NotificationUrgency::High, $reopened->urgency);
    }

    // Flujo: un lote en cuarentena puede generar un aviso alto con la referencia y los permisos requeridos.
    public function test_alert_requires_an_adult_week_and_notifies_only_recipients_with_access(): void
    {
        // Preparación: crea un exceso y asigna permisos a cuentas de dos UP distintas.
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00:00', config('lots.timezone')));
        config(['identity.pin.pepper' => 'poultry-house-capacity-test']);
        $house = PoultryHouse::factory()->create(['bird_capacity' => 99]);
        Flock::factory()->quarantined()->create(['poultry_house_id' => $house->id]);
        $ownUnit = ProductionUnit::query()->findOrFail($house->production_unit_id);
        $otherUnit = ProductionUnit::factory()->create();
        $local = User::factory()->devicePin($ownUnit)->create();
        $external = User::factory()->devicePin($otherUnit)->create();
        $noHouseAccess = User::factory()->create();
        $housePermission = Permission::findOrCreate('can_view_poultry_houses', 'web');
        $inboxPermission = Permission::findOrCreate('can_view_notifications', 'web');
        $local->roles()->firstOrFail()->givePermissionTo($housePermission, $inboxPermission);
        $external->roles()->firstOrFail()->givePermissionTo($housePermission, $inboxPermission);
        $noHouseAccess->roles()->firstOrFail()->givePermissionTo($inboxPermission);

        // Consulta: sin la semana de adultez configurada no se publica ningún aviso.
        $this->artisan('galpones:evaluar-capacidad')->assertSuccessful();
        $this->assertDatabaseCount('app_notifications', 0);

        // Mutación y consulta: al configurar la adultez, la cuenta PIN de la UP recibe el aviso alto.
        WeighingReferenceSettings::factory()->create(['adult_from_week' => 5]);
        $this->artisan('galpones:evaluar-capacidad')->assertSuccessful();
        $notice = AppNotification::query()->sole();
        $this->assertSame($local->id, $notice->recipient_id);
        $this->assertSame(NotificationUrgency::High, $notice->urgency);
    }
}
