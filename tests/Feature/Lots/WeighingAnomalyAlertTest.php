<?php

namespace Tests\Feature\Lots;

use App\Models\Notifications\AppNotification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

final class WeighingAnomalyAlertTest extends LotsTestCase
{
    // Flujo: el ingreso confirmado genera una alerta alta y una revisión posterior la resuelve.
    public function test_daily_anomaly_requires_a_separate_review_and_resolves_for_every_recipient(): void
    {
        // Preparación: configura el rango, dos destinatarios y un lote con admisión.
        $viewer = $this->signIn(['can_view_weighings', 'can_view_notifications']);
        $reviewer = $this->signIn(['can_view_weighings', 'can_manage_weighings', 'can_manage_weighing_settings', 'can_view_notifications']);
        $this->setRange('20.0');
        $flock = $this->flockWithHistory();
        $url = '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos';
        $payload = ['mode' => 'group', 'bird_count' => 2, 'total_weight' => '50.0'];
        $key = (string) Str::uuid();

        // Request: rechaza el ingreso sin confirmación y publica una vez por destinatario al guardarlo.
        $this->command('POST', $url, $payload, $key)->assertConflict();
        $this->assertDatabaseCount('app_notifications', 0);
        $this->command('POST', $url, ['mode' => 'individual', 'weight' => '15.0'])->assertCreated();
        $this->assertDatabaseCount('app_notifications', 0);
        $payload['confirm_out_of_range'] = true;
        $created = $this->command('POST', $url, $payload, $key)->assertCreated()
            ->assertJsonPath('data.entry.anomaly_reviewed_at', null);
        $this->command('POST', $url, $payload, $key)->assertCreated();
        $dailyId = $created->json('data.daily_weighing.id');
        $entryId = $created->json('data.entry.id');
        $version = $created->json('data.daily_weighing.version');
        $reviewUrl = "/pesajes-diarios/{$dailyId}/ingresos/{$entryId}/confirmacion-anomalia";
        $this->assertDatabaseCount('app_notifications', 2);
        $notice = AppNotification::query()->where('recipient_id', $reviewer->id)->sole();
        $this->getJson('/api/v1/notificaciones/'.$notice->public_id)->assertOk()
            ->assertJsonPath('data.tipo', 'weighing_anomaly')
            ->assertJsonPath('data.urgencia', 'alto')
            ->assertJsonPath('data.estado_resolucion', 'no_resuelto')
            ->assertJsonPath('data.entidad.id', $entryId)
            ->assertJsonPath('data.entidad.ruta', '/administracion/lotes/lotes/'.$flock->public_id.'/pesajes/'.$dailyId);

        // Request: impide revisar sin permiso o con una versión anterior a la observada.
        Sanctum::actingAs($viewer, ['api:access']);
        $this->command('POST', $reviewUrl, ['version' => $version])->assertForbidden();
        Sanctum::actingAs($reviewer, ['api:access']);
        $this->command('POST', $reviewUrl, ['version' => $version - 1])->assertConflict()
            ->assertJsonPath('code', 'WEIGHING_ANOMALY_VERSION_CONFLICT');

        // Request: confirma el dato y repite la clave sin duplicar la auditoría.
        $reviewKey = (string) Str::uuid();
        $review = $this->command('POST', $reviewUrl, ['version' => $version], $reviewKey)->assertOk()
            ->assertJsonPath('data.entry.anomaly_reviewed_by', $reviewer->id)
            ->assertJsonPath('data.entry.average_weight_g', '25.000000')
            ->assertJsonPath('data.entry.outside_expected_range', true)
            ->assertJsonPath('data.daily_weighing.anomalous_entry_count', 1);
        $this->assertNotNull($review->json('data.entry.anomaly_reviewed_at'));
        $this->command('POST', $reviewUrl, ['version' => $version], $reviewKey)->assertOk()
            ->assertJsonPath('data.operation_id', $review->json('data.operation_id'));
        $this->assertSame(1, \DB::table('activity_log')->where('event', 'weighing_anomaly_confirmed')->count());
        $this->assertDatabaseCount('app_notifications', 2);
        $this->assertSame(0, AppNotification::query()->whereNull('resolved_at')->count());
        $this->getJson('/api/v1/notificaciones/'.$notice->public_id)->assertOk()->assertJsonPath('data.estado_resolucion', 'resuelto');
    }

    // Flujo: la rectificación distingue un cambio de notas de un cambio en el dato verificado.
    public function test_independent_weighing_reopens_review_only_for_a_new_anomaly(): void
    {
        // Preparación: registra un pesaje independiente fuera de rango y revisa su primera versión.
        $this->signIn(['can_view_weighings', 'can_manage_weighings', 'can_manage_weighing_settings', 'can_view_notifications']);
        $this->setRange('20.0');
        $flock = $this->flockWithHistory();
        $created = $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id, 'mode' => 'individual', 'unit' => 'g',
            'measurements' => [['weight' => '25.0']], 'confirm_out_of_range' => true,
        ])->assertCreated();
        $id = $created->json('data.weighing.id');
        $path = '/pesajes/'.$id;
        $notice = AppNotification::query()->whereNull('resolved_at')->sole();
        $this->assertSame('weighing', $notice->entity_type);
        $this->assertSame($id, $notice->entity_id);
        $this->command('POST', $path.'/confirmacion-anomalia', ['version' => 1])->assertOk()
            ->assertJsonPath('data.weighing.version', 2);
        $this->assertNotNull($notice->refresh()->resolved_at);

        // Request: las observaciones no afectan la confirmación, pero otro peso fuera de rango requiere nueva revisión.
        $notes = $this->command('PATCH', $path, ['version' => 2, 'correction_reason' => 'Nota omitida', 'notes' => 'Nota nueva'])->assertOk();
        $this->assertNotNull($notes->json('data.weighing.anomaly_reviewed_at'));
        $this->assertDatabaseCount('app_notifications', 1);
        $this->command('PATCH', $path, [
            'version' => 3, 'correction_reason' => 'Peso rectificado',
            'measurements' => [['weight' => '30.0']], 'confirm_out_of_range' => true,
        ])->assertOk()->assertJsonPath('data.weighing.anomaly_reviewed_at', null);
        $this->assertDatabaseCount('app_notifications', 2);
        $newNotice = AppNotification::query()->whereNull('resolved_at')->sole();

        // Request: volver al rango esperado resuelve el aviso y deja de admitir confirmaciones.
        $this->command('PATCH', $path, [
            'version' => 4, 'correction_reason' => 'Peso rectificado', 'measurements' => [['weight' => '15.0']],
        ])->assertOk()->assertJsonPath('data.weighing.outside_expected_range', false);
        $this->assertNotNull($newNotice->refresh()->resolved_at);
        $this->command('POST', $path.'/confirmacion-anomalia', ['version' => 5])->assertConflict()
            ->assertJsonPath('code', 'WEIGHING_ANOMALY_NOT_PRESENT');
    }

    // Flujo: reclasificar o eliminar un ingreso cierra los avisos que ya no requieren revisión.
    public function test_daily_reclassification_and_deletion_synchronize_the_alert(): void
    {
        // Preparación: registra una entrada anómala en la jornada abierta.
        $this->signIn(['can_view_weighings', 'can_manage_weighings', 'can_manage_weighing_settings', 'can_view_notifications']);
        $this->setRange('20.0');
        $flock = $this->flockWithHistory();
        $created = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', [
            'mode' => 'individual', 'weight' => '25.0', 'confirm_out_of_range' => true,
        ])->assertCreated();
        $notice = AppNotification::query()->whereNull('resolved_at')->sole();
        $dailyId = $created->json('data.daily_weighing.id');
        $entryId = $created->json('data.entry.id');

        // Mutación: ampliar el rango resuelve el aviso y volver a estrecharlo publica otro.
        $this->setRange('30.0', 1);
        $this->assertNotNull($notice->refresh()->resolved_at);
        $this->setRange('20.0', 2);
        $replacement = AppNotification::query()->whereNull('resolved_at')->sole();
        $this->assertNotSame($notice->id, $replacement->id);

        // Request: eliminar el ingreso resuelve el segundo aviso y bloquea su revisión.
        $dailyUrl = '/pesajes-diarios/'.$dailyId;
        $version = $this->getJson('/api/v1'.$dailyUrl)->assertOk()->json('data.version');
        $this->command('DELETE', $dailyUrl.'/ingresos/'.$entryId, ['version' => $version, 'reason' => 'Error de captura'])->assertOk();
        $this->assertNotNull($replacement->refresh()->resolved_at);
        $this->command('POST', $dailyUrl.'/ingresos/'.$entryId.'/confirmacion-anomalia', ['version' => $version + 1])->assertNotFound();
    }

    private function setRange(string $maximum, ?int $version = null): void
    {
        $this->command('PUT', '/configuracion-pesajes', [
            ...($version === null ? [] : ['version' => $version]),
            'adult_from_week' => 18, 'unit' => 'g',
            'chick_min_weight' => '10.0', 'chick_max_weight' => $maximum,
            'adult_min_weight' => '10.0', 'adult_max_weight' => $maximum,
        ])->assertOk();
    }
}
