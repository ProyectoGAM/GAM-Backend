<?php

namespace Tests\Feature\Lots;

use App\Models\Lots\DailyWeighing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class DailyWeighingEndpointTest extends LotsTestCase
{
    /** Registra ingresos mezclados, calcula el promedio por ave y evita duplicados por clave. */
    public function test_mixed_entries_are_aggregated_and_retried_once(): void
    {
        // Preparación: crea un lote con historia y permisos de lectura y registro.
        $this->signIn(['weighings.view', 'weighings.manage']);
        $flock = $this->flockWithHistory(20);
        $key = (string) Str::uuid();

        // Request: registra un ave y un grupo, y reintenta el segundo comando.
        $first = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'individual', 'weight' => '20.0'])->assertCreated();
        $second = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'group', 'bird_count' => 3, 'total_weight' => '90.0'], $key)->assertCreated();
        $replay = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'group', 'bird_count' => 3, 'total_weight' => '90.0'], $key)->assertCreated();

        // Verificación: la jornada mantiene cuatro aves, 110 gramos y dos ingresos.
        $id = $first->json('data.daily_weighing.id');
        $this->assertSame($id, $second->json('data.daily_weighing.id'));
        $this->assertSame($second->json('data.entry.id'), $replay->json('data.entry.id'));
        $this->getJson('/api/v1/pesajes-diarios/'.$id)->assertOk()
            ->assertJsonPath('data.represented_bird_count', 4)
            ->assertJsonPath('data.average_weight_g', '27.500000')
            ->assertJsonCount(2, 'data.entries');
        $this->assertDatabaseCount('daily_weighing_entries', 2);
        $this->assertDatabaseHas('activity_log', ['event' => 'daily_weighing_entry_added']);
    }

    /** Exige confirmación de un promedio grupal fuera del rango y no guarda el intento cancelado. */
    public function test_out_of_range_conflict_has_no_effect_until_confirmed(): void
    {
        // Preparación: fija el rango esperado y crea un lote vigente.
        $this->signIn(['weighings.view', 'weighings.manage', 'weighing-settings.manage']);
        $this->settings('10.0', '20.0');
        $flock = $this->flockWithHistory(20);
        $key = (string) Str::uuid();
        $payload = ['mode' => 'group', 'bird_count' => 2, 'total_weight' => '50.0'];

        // Request: intenta guardar el grupo sin confirmación y luego confirma el mismo ingreso.
        $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', $payload, $key)
            ->assertConflict()->assertJsonPath('code', 'DAILY_WEIGHING_OUT_OF_RANGE_CONFIRMATION_REQUIRED');
        $this->assertDatabaseCount('daily_weighings', 0);
        $this->assertDatabaseCount('daily_weighing_entries', 0);
        $confirmed = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', [...$payload, 'confirm_out_of_range' => true], $key)->assertCreated();

        // Verificación: el grupo produce un solo dato anómalo y conserva la referencia.
        $confirmed->assertJsonPath('data.daily_weighing.anomalous_entry_count', 1)
            ->assertJsonPath('data.daily_weighing.expected_range.min_weight_g', '10.0')
            ->assertJsonPath('data.entry.average_weight_g', '25.000000');
        $this->assertDatabaseCount('daily_weighing_entries', 1);
    }

    /** Elimina un ingreso con versión y motivo, incluso cerrado, y recalcula el último dato. */
    public function test_deleting_closed_entry_recalculates_totals_and_keeps_audit(): void
    {
        // Preparación: crea dos ingresos en una jornada que luego queda cerrada.
        $this->signIn(['weighings.view', 'weighings.manage']);
        $flock = $this->flockWithHistory(20);
        $first = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'individual', 'weight' => '20.0'])->assertCreated();
        $second = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'group', 'bird_count' => 2, 'total_weight' => '60.0'])->assertCreated();
        $dailyId = $first->json('data.daily_weighing.id');
        $this->travel(1)->days();

        // Request: elimina el ingreso más reciente y después el único restante.
        $deleted = $this->command('DELETE', '/pesajes-diarios/'.$dailyId.'/ingresos/'.$second->json('data.entry.id'), ['version' => $second->json('data.daily_weighing.version'), 'reason' => 'Error de captura'])->assertOk();
        $deleted->assertJsonPath('data.daily_weighing.status', 'closed')
            ->assertJsonPath('data.daily_weighing.represented_bird_count', 1)
            ->assertJsonPath('data.daily_weighing.last_entry.id', $first->json('data.entry.id'));
        $this->command('DELETE', '/pesajes-diarios/'.$dailyId.'/ingresos/'.$first->json('data.entry.id'), ['version' => $deleted->json('data.daily_weighing.version'), 'reason' => 'Registro incorrecto'])->assertOk()
            ->assertJsonPath('data.daily_weighing.represented_bird_count', 0)
            ->assertJsonPath('data.daily_weighing.average_weight_g', null)
            ->assertJsonPath('data.daily_weighing.last_entry', null);

        // Verificación: conserva las filas históricas y dos auditorías de compensación.
        $this->assertDatabaseCount('daily_weighing_entries', 2);
        $this->assertSame(2, \DB::table('activity_log')->where('event', 'daily_weighing_entry_deleted')->count());
    }

    /** Separa fechas locales al cruzar medianoche aunque la hora se persista en UTC. */
    public function test_local_midnight_starts_a_new_daily_weighing(): void
    {
        // Preparación: fija la hora local justo antes de medianoche.
        $this->travelTo(CarbonImmutable::parse('2026-10-07 23:59:59', 'America/Montevideo'));
        $this->signIn(['weighings.view', 'weighings.manage']);
        $flock = $this->flockWithHistory(20);

        // Request: registra un ingreso antes y otro después de medianoche.
        $first = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'individual', 'weight' => '20.0'])->assertCreated();
        $this->travel(2)->seconds();
        $second = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'individual', 'weight' => '21.0'])->assertCreated();

        // Verificación: devuelve dos jornadas con estados distintos.
        $this->assertNotSame($first->json('data.daily_weighing.id'), $second->json('data.daily_weighing.id'));
        $this->assertSame('2026-10-07', $first->json('data.daily_weighing.date'));
        $this->assertSame('2026-10-08', $second->json('data.daily_weighing.date'));
        $this->assertSame('2026-10-08T02:59:59+00:00', $first->json('data.entry.occurred_at'));
        $this->assertSame(2, DailyWeighing::query()->count());
        $this->getJson('/api/v1/lotes/'.$flock->public_id.'/pesajes-diarios/2026-10-07')->assertOk()
            ->assertJsonPath('data.status', 'closed');
        $this->getJson('/api/v1/lotes/'.$flock->public_id.'/pesajes-diarios/2026-10-09')->assertNotFound();
    }

    /** La curva diaria usa sólo individuos y explica cuándo no puede calcularse. */
    public function test_distribution_excludes_groups_and_reports_insufficient_sample(): void
    {
        // Preparación: registra un grupo y un peso individual.
        $this->signIn(['weighings.view', 'weighings.manage']);
        $flock = $this->flockWithHistory(20);
        $group = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'group', 'bird_count' => 3, 'total_weight' => '90.0'])->assertCreated();
        $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'individual', 'weight' => '20.0'])->assertCreated();

        // Consulta: obtiene la distribución de la jornada.
        $this->getJson('/api/v1/pesajes-diarios/'.$group->json('data.daily_weighing.id').'/distribucion')->assertOk()
            ->assertJsonPath('data.n', 1)
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'insufficient_sample')
            ->assertJsonPath('data.curve', null);
    }

    /** Eliminar un grupo anómalo descuenta un dato y todas sus aves de la muestra. */
    public function test_deleting_anomalous_group_recalculates_sample(): void
    {
        // Preparación: crea un ingreso individual normal y un grupo fuera de rango confirmado.
        $this->signIn(['weighings.view', 'weighings.manage', 'weighing-settings.manage']);
        $this->settings('10.0', '20.0');
        $flock = $this->flockWithHistory(20);
        $first = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'individual', 'weight' => '15.0'])->assertCreated();
        $group = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', [
            'mode' => 'group', 'bird_count' => 3, 'total_weight' => '90.0', 'confirm_out_of_range' => true,
        ])->assertCreated()->assertJsonPath('data.daily_weighing.anomalous_entry_count', 1);

        // Request: elimina únicamente el grupo.
        $this->command('DELETE', '/pesajes-diarios/'.$first->json('data.daily_weighing.id').'/ingresos/'.$group->json('data.entry.id'), [
            'version' => $group->json('data.daily_weighing.version'), 'reason' => 'Grupo mal pesado',
        ])->assertOk()
            ->assertJsonPath('data.daily_weighing.represented_bird_count', 1)
            ->assertJsonPath('data.daily_weighing.average_weight_g', '15.000000')
            ->assertJsonPath('data.daily_weighing.anomalous_entry_count', 0);

        // Verificación: el ingreso eliminado conserva su marca histórica.
        $this->assertDatabaseHas('daily_weighing_entries', ['public_id' => $group->json('data.entry.id'), 'outside_expected_range' => true]);
    }

    /** Dos individuos iguales producen histograma y una razón explícita sin curva. */
    public function test_distribution_reports_zero_variance(): void
    {
        // Preparación: registra dos pesos individuales iguales.
        $this->signIn(['weighings.view', 'weighings.manage']);
        $flock = $this->flockWithHistory(20);
        $first = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'individual', 'weight' => '20.0'])->assertCreated();
        $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'individual', 'weight' => '20.0'])->assertCreated();

        // Consulta: solicita la distribución diaria.
        $this->getJson('/api/v1/pesajes-diarios/'.$first->json('data.daily_weighing.id').'/distribucion')->assertOk()
            ->assertJsonPath('data.n', 2)
            ->assertJsonPath('data.reason', 'zero_variance')
            ->assertJsonPath('data.curve', null)
            ->assertJsonPath('data.bins.0.count', 2);
    }

    /** Los cambios globales reclasifican jornadas abiertas y preservan las cerradas. */
    public function test_settings_update_reclassifies_open_daily_weighings(): void
    {
        // Preparación: guarda un rango inicial y registra un ingreso normal.
        $this->signIn(['weighings.view', 'weighings.manage', 'weighing-settings.manage']);
        $this->settings('10.0', '30.0');
        $flock = $this->flockWithHistory(20);
        $created = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'individual', 'weight' => '25.0'])->assertCreated();

        // Mutación: estrecha el rango global durante la misma fecha local.
        $this->command('PUT', '/configuracion-pesajes', [
            'version' => 1, 'adult_from_week' => 18, 'unit' => 'g',
            'chick_min_weight' => '10.0', 'chick_max_weight' => '20.0',
            'adult_min_weight' => '10.0', 'adult_max_weight' => '20.0',
        ])->assertOk();

        // Verificación: la jornada adopta la versión y reclasifica su único ingreso.
        $this->getJson('/api/v1/pesajes-diarios/'.$created->json('data.daily_weighing.id'))->assertOk()
            ->assertJsonPath('data.expected_range.reference_version', 2)
            ->assertJsonPath('data.anomalous_entry_count', 1)
            ->assertJsonPath('data.entries.0.outside_expected_range', true);
    }

    /** Rechaza una versión vieja sin alterar la medición ni sus agregados. */
    public function test_delete_rejects_stale_version(): void
    {
        // Preparación: registra un ingreso y conserva una versión anterior de la jornada.
        $this->signIn(['weighings.view', 'weighings.manage']);
        $flock = $this->flockWithHistory(20);
        $first = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'individual', 'weight' => '20.0'])->assertCreated();
        $second = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'individual', 'weight' => '30.0'])->assertCreated();

        // Request: intenta eliminar con la versión anterior.
        $this->command('DELETE', '/pesajes-diarios/'.$first->json('data.daily_weighing.id').'/ingresos/'.$second->json('data.entry.id'), [
            'version' => $first->json('data.daily_weighing.version'), 'reason' => 'Error de captura',
        ])->assertConflict()->assertJsonPath('code', 'DAILY_WEIGHING_VERSION_CONFLICT');

        // Verificación: conserva ambos ingresos activos.
        $this->getJson('/api/v1/pesajes-diarios/'.$first->json('data.daily_weighing.id'))->assertOk()
            ->assertJsonPath('data.represented_bird_count', 2)
            ->assertJsonCount(2, 'data.entries');
    }

    /** Pagina ingresos y jornadas con cursores estables y conserva el rango de una jornada cerrada. */
    public function test_cursor_pages_do_not_skip_entries_or_daily_weighings(): void
    {
        // Preparación: crea dos ingresos y dos fechas de pesaje para el mismo lote.
        $this->signIn(['weighings.view', 'weighings.manage', 'weighing-settings.manage']);
        $this->settings('10.0', '30.0');
        $flock = $this->flockWithHistory(20);
        $first = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'individual', 'weight' => '20.0'])->assertCreated();
        $second = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'individual', 'weight' => '21.0'])->assertCreated();
        $this->travel(1)->days();
        $nextDay = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'individual', 'weight' => '22.0'])->assertCreated();

        // Consulta: recorre ambas páginas de jornadas y luego ambas páginas de ingresos.
        $list = $this->getJson('/api/v1/pesajes-diarios?flock_id='.$flock->public_id.'&per_page=1')->assertOk();
        $nextList = $this->getJson('/api/v1/pesajes-diarios?flock_id='.$flock->public_id.'&per_page=1&cursor='.urlencode($list->json('next_cursor')))->assertOk();
        $detail = $this->getJson('/api/v1/pesajes-diarios/'.$first->json('data.daily_weighing.id').'?per_page=1')->assertOk();
        $nextDetail = $this->getJson('/api/v1/pesajes-diarios/'.$first->json('data.daily_weighing.id').'?per_page=1&cursor='.urlencode($detail->json('data.next_cursor')))->assertOk();

        // Verificación: no omite ninguna jornada o fila y mantiene cerrado el día anterior.
        $this->assertSame($nextDay->json('data.daily_weighing.id'), $list->json('data.0.id'));
        $this->assertSame($first->json('data.daily_weighing.id'), $nextList->json('data.0.id'));
        $this->assertSame($first->json('data.entry.id'), $detail->json('data.entries.0.id'));
        $this->assertSame($second->json('data.entry.id'), $nextDetail->json('data.entries.0.id'));
        $this->assertSame('closed', $nextList->json('data.0.status'));
        $this->assertSame(1, $nextList->json('data.0.expected_range.reference_version'));

        // Mutación: cambia la referencia global con la primera jornada ya cerrada.
        $this->command('PUT', '/configuracion-pesajes', [
            'version' => 1, 'adult_from_week' => 18, 'unit' => 'g',
            'chick_min_weight' => '10.0', 'chick_max_weight' => '25.0',
            'adult_min_weight' => '10.0', 'adult_max_weight' => '25.0',
        ])->assertOk();

        // Verificación: sólo la jornada abierta adopta la nueva referencia.
        $this->getJson('/api/v1/pesajes-diarios/'.$first->json('data.daily_weighing.id'))->assertOk()
            ->assertJsonPath('data.expected_range.reference_version', 1);
        $this->getJson('/api/v1/pesajes-diarios/'.$nextDay->json('data.daily_weighing.id'))->assertOk()
            ->assertJsonPath('data.expected_range.reference_version', 2);
    }

    /** Deniega la lectura y el registro según el permiso funcional asignado. */
    public function test_read_and_write_permissions_are_distinct(): void
    {
        // Preparación: crea una jornada como usuario con permisos completos.
        $this->signIn(['weighings.view', 'weighings.manage']);
        $flock = $this->flockWithHistory(20);
        $created = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'individual', 'weight' => '20.0'])->assertCreated();

        // Request: un usuario de sólo lectura consulta pero no registra.
        $this->signIn(['weighings.view']);
        $this->getJson('/api/v1/pesajes-diarios/'.$created->json('data.daily_weighing.id'))->assertOk();
        $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'individual', 'weight' => '21.0'])->assertForbidden();

        // Verificación: un usuario sin lectura no obtiene la jornada.
        $this->signIn(['weighings.manage']);
        $this->getJson('/api/v1/pesajes-diarios/'.$created->json('data.daily_weighing.id'))->assertForbidden();
    }

    private function settings(string $minimum, string $maximum): void
    {
        $this->command('PUT', '/configuracion-pesajes', [
            'adult_from_week' => 18, 'unit' => 'g',
            'chick_min_weight' => $minimum, 'chick_max_weight' => $maximum,
            'adult_min_weight' => $minimum, 'adult_max_weight' => $maximum,
        ])->assertOk();
    }
}
