<?php

namespace Tests\Feature\Lots;

use App\Models\Lots\Breed;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class BreedWeighingRangeEndpointTest extends LotsTestCase
{
    // Flujo: una raza nueva hereda los rangos globales y otra conserva sus límites propios.
    public function test_breeds_inherit_global_ranges_until_overridden(): void
    {
        // Preparación: fija la fecha, autentica y establece la referencia general.
        $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00', 'America/Montevideo'));
        $this->signIn(['breeds.view', 'breeds.manage', 'weighing-settings.manage']);
        $this->settings();

        // Requests: crea una raza heredada y otra con ambos rangos personalizados.
        $default = $this->command('POST', '/breeds', ['name' => 'Raza 1'])->assertCreated();
        $custom = $this->command('POST', '/breeds', [
            'name' => 'Raza 2',
            'chick_min_weight_g' => '50.0', 'chick_max_weight_g' => '100.0',
            'adult_min_weight_g' => '100.0', 'adult_max_weight_g' => '2000.0',
        ])->assertCreated();
        $this->getJson('/api/v1/breeds')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.expected_ranges.chick.source', 'global')
            ->assertJsonPath('data.1.expected_ranges.adult.max_weight_g', '2000.0');

        // Verificación: la respuesta distingue los valores efectivos y su origen.
        $default->assertJsonPath('data.catalog.expected_ranges.chick.source', 'global')
            ->assertJsonPath('data.catalog.expected_ranges.chick.min_weight_g', '10.0')
            ->assertJsonPath('data.catalog.expected_ranges.adult.max_weight_g', '3000.0')
            ->assertJsonPath('data.catalog.range_overrides.chick_min_weight_g', null);
        $custom->assertJsonPath('data.catalog.expected_ranges.chick.source', 'breed')
            ->assertJsonPath('data.catalog.expected_ranges.chick.min_weight_g', '50.0')
            ->assertJsonPath('data.catalog.expected_ranges.adult.max_weight_g', '2000.0')
            ->assertJsonPath('data.catalog.range_overrides.adult_max_weight_g', '2000.0');
        $this->assertSame(2, DB::table('activity_log')->where('event', 'breed_created')->count());
    }

    // Flujo: el pesaje diario y el pesaje independiente comparan contra el rango de su raza.
    public function test_adult_weighings_use_each_breeds_range_and_capture_its_origin(): void
    {
        // Preparación: crea dos razas adultas con máximo global 3000 y máximo particular 2000.
        $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00', 'America/Montevideo'));
        $this->signIn(['breeds.manage', 'weighing-settings.manage', 'weighings.manage', 'weighings.view']);
        $this->settings();
        $default = Breed::query()->findOrFail($this->command('POST', '/breeds', ['name' => 'Raza 1'])->assertCreated()->json('data.catalog.id'));
        $custom = Breed::query()->findOrFail($this->command('POST', '/breeds', [
            'name' => 'Raza 2', 'adult_min_weight_g' => '100.0', 'adult_max_weight_g' => '2000.0',
        ])->assertCreated()->json('data.catalog.id'));
        $defaultFlock = $this->adultFlock($default);
        $customFlock = $this->adultFlock($custom);

        // Requests: 2500 gramos es normal para la primera raza y requiere confirmación para la segunda.
        $this->command('POST', '/lotes/'.$defaultFlock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'individual', 'weight' => '2500.0'])
            ->assertCreated()->assertJsonPath('data.daily_weighing.expected_range.source', 'global');
        $this->command('POST', '/lotes/'.$customFlock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'individual', 'weight' => '2500.0'])
            ->assertConflict()->assertJsonPath('code', 'DAILY_WEIGHING_OUT_OF_RANGE_CONFIRMATION_REQUIRED');
        $daily = $this->command('POST', '/lotes/'.$customFlock->public_id.'/pesajes-diarios/ingresos', [
            'mode' => 'individual', 'weight' => '2500.0', 'confirm_out_of_range' => true,
        ])->assertCreated();
        $daily->assertJsonPath('data.daily_weighing.expected_range.source', 'breed')
            ->assertJsonPath('data.daily_weighing.expected_range.max_weight_g', '2000.0')
            ->assertJsonPath('data.daily_weighing.anomalous_entry_count', 1);
        $legacy = $this->command('POST', '/pesajes', [
            'flock_id' => $customFlock->public_id, 'mode' => 'individual', 'unit' => 'g',
            'measurements' => [['weight' => '1500.0']],
        ])->assertCreated();
        $legacy->assertJsonPath('data.weighing.expected_range.source', 'breed')
            ->assertJsonPath('data.weighing.reference.adult_max_weight_g', '2000.0')
            ->assertJsonPath('data.weighing.reference.breed_id', $custom->id);
    }

    // Flujo: cambiar una raza reclasifica sólo su jornada abierta y permite recuperar la herencia.
    public function test_breed_changes_reclassify_open_day_and_preserve_closed_day(): void
    {
        // Preparación: registra un peso de 150 gramos dentro del rango heredado de pollitos.
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'America/Montevideo'));
        $this->signIn(['breeds.manage', 'weighing-settings.manage', 'weighings.manage', 'weighings.view']);
        $this->command('PUT', '/configuracion-pesajes', [
            'adult_from_week' => 18, 'unit' => 'g',
            'chick_min_weight' => '10.0', 'chick_max_weight' => '200.0',
            'adult_min_weight' => '100.0', 'adult_max_weight' => '3000.0',
        ])->assertOk();
        $breed = Breed::query()->findOrFail($this->command('POST', '/breeds', ['name' => 'Raza editable'])->assertCreated()->json('data.catalog.id'));
        $flock = $this->flockWithHistory(20, $breed);
        $closed = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'individual', 'weight' => '150.0'])->assertCreated();
        $this->travel(1)->days();
        $open = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'individual', 'weight' => '150.0'])->assertCreated();

        // Mutación: el máximo propio baja a 100 gramos y reclasifica el día vigente.
        $this->command('PATCH', '/breeds/'.$breed->id, [
            'version' => 1, 'chick_min_weight_g' => '50.0', 'chick_max_weight_g' => '100.0',
        ])->assertOk()->assertJsonPath('data.catalog.expected_ranges.chick.source', 'breed');
        $this->getJson('/api/v1/pesajes-diarios/'.$open->json('data.daily_weighing.id'))->assertOk()
            ->assertJsonPath('data.anomalous_entry_count', 1)
            ->assertJsonPath('data.expected_range.max_weight_g', '100.0');
        $this->getJson('/api/v1/pesajes-diarios/'.$closed->json('data.daily_weighing.id'))->assertOk()
            ->assertJsonPath('data.anomalous_entry_count', 0)
            ->assertJsonPath('data.expected_range.max_weight_g', '200.0');

        // Mutación: ambos límites nulos restauran el rango global y limpian la anomalía abierta.
        $this->command('PATCH', '/breeds/'.$breed->id, [
            'version' => 2, 'chick_min_weight_g' => null, 'chick_max_weight_g' => null,
        ])->assertOk()->assertJsonPath('data.catalog.expected_ranges.chick.source', 'global');
        $this->getJson('/api/v1/pesajes-diarios/'.$open->json('data.daily_weighing.id'))->assertOk()
            ->assertJsonPath('data.anomalous_entry_count', 0)
            ->assertJsonPath('data.expected_range.max_weight_g', '200.0');
    }

    // Flujo: la API rechaza rangos incompletos o invertidos antes de modificar la raza.
    public function test_breed_range_requires_a_valid_pair(): void
    {
        // Preparación: autentica a una persona con permiso de administración de razas.
        $this->signIn(['breeds.manage']);

        // Requests: un límite aislado y un mínimo mayor que el máximo son inválidos.
        $this->command('POST', '/breeds', ['name' => 'Incompleta', 'chick_min_weight_g' => '50.0'])
            ->assertUnprocessable()->assertJsonValidationErrors('chick_min_weight_g');
        $this->command('POST', '/breeds', [
            'name' => 'Invertida', 'adult_min_weight_g' => '2000.0', 'adult_max_weight_g' => '100.0',
        ])->assertUnprocessable()->assertJsonValidationErrors('adult_min_weight_g');
        $this->assertDatabaseCount('breeds', 0);
    }

    // Flujo: al editar el rango global, sólo las etapas heredadas cambian en jornadas abiertas.
    public function test_global_update_keeps_custom_breed_range_and_reclassifies_inherited_range(): void
    {
        // Preparación: registra el mismo peso para una raza heredada y otra con rango propio.
        $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00', 'America/Montevideo'));
        $this->signIn(['breeds.manage', 'weighing-settings.manage', 'weighings.manage', 'weighings.view']);
        $this->settings();
        $inherited = Breed::query()->findOrFail($this->command('POST', '/breeds', ['name' => 'Heredada'])->assertCreated()->json('data.catalog.id'));
        $custom = Breed::query()->findOrFail($this->command('POST', '/breeds', [
            'name' => 'Propia', 'chick_min_weight_g' => '50.0', 'chick_max_weight_g' => '100.0',
        ])->assertCreated()->json('data.catalog.id'));
        $inheritedFlock = $this->flockWithHistory(20, $inherited);
        $customFlock = $this->flockWithHistory(20, $custom);
        $inheritedDaily = $this->command('POST', '/lotes/'.$inheritedFlock->public_id.'/pesajes-diarios/ingresos', [
            'mode' => 'individual', 'weight' => '75.0',
        ])->assertCreated();
        $customDaily = $this->command('POST', '/lotes/'.$customFlock->public_id.'/pesajes-diarios/ingresos', [
            'mode' => 'individual', 'weight' => '75.0',
        ])->assertCreated();

        // Mutación: sube el mínimo global de pollitos por encima del peso capturado.
        $this->command('PUT', '/configuracion-pesajes', [
            'version' => 1, 'adult_from_week' => 18, 'unit' => 'g',
            'chick_min_weight' => '80.0', 'chick_max_weight' => '120.0',
            'adult_min_weight' => '100.0', 'adult_max_weight' => '3000.0',
        ])->assertOk();

        // Verificación: la raza heredada pasa a anómala y la personalizada conserva su rango.
        $this->getJson('/api/v1/pesajes-diarios/'.$inheritedDaily->json('data.daily_weighing.id'))->assertOk()
            ->assertJsonPath('data.anomalous_entry_count', 1)
            ->assertJsonPath('data.expected_range.min_weight_g', '80.0')
            ->assertJsonPath('data.expected_range.source', 'global');
        $this->getJson('/api/v1/pesajes-diarios/'.$customDaily->json('data.daily_weighing.id'))->assertOk()
            ->assertJsonPath('data.anomalous_entry_count', 0)
            ->assertJsonPath('data.expected_range.min_weight_g', '50.0')
            ->assertJsonPath('data.expected_range.source', 'breed');
    }

    // Flujo: una corrección mantiene el rango de raza capturado antes de cambiar su configuración.
    public function test_correction_retains_original_breed_reference(): void
    {
        // Preparación: crea un pesaje adulto con máximo propio de 2000 gramos.
        $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00', 'America/Montevideo'));
        $this->signIn(['breeds.manage', 'weighing-settings.manage', 'weighings.manage', 'weighings.view']);
        $this->settings();
        $breed = Breed::query()->findOrFail($this->command('POST', '/breeds', [
            'name' => 'Histórica', 'adult_min_weight_g' => '100.0', 'adult_max_weight_g' => '2000.0',
        ])->assertCreated()->json('data.catalog.id'));
        $flock = $this->adultFlock($breed);
        $created = $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id, 'mode' => 'individual', 'unit' => 'g',
            'measurements' => [['weight' => '1500.0']],
        ])->assertCreated();
        $id = $created->json('data.weighing.id');

        // Mutación: la raza adopta un máximo distinto y se corrigen sólo las notas del pesaje.
        $this->command('PATCH', '/breeds/'.$breed->id, [
            'version' => 1, 'adult_min_weight_g' => '100.0', 'adult_max_weight_g' => '1200.0',
        ])->assertOk();
        $this->command('PATCH', '/pesajes/'.$id, [
            'version' => 1, 'correction_reason' => 'Se corrigieron las notas', 'notes' => 'Lectura revisada',
        ])->assertOk()
            ->assertJsonPath('data.weighing.expected_range.max_weight_g', '2000.0')
            ->assertJsonPath('data.weighing.expected_range.source', 'breed')
            ->assertJsonPath('data.weighing.reference.breed_version', 1);
    }

    private function settings(): void
    {
        $this->command('PUT', '/configuracion-pesajes', [
            'adult_from_week' => 18, 'unit' => 'g',
            'chick_min_weight' => '10.0', 'chick_max_weight' => '100.0',
            'adult_min_weight' => '100.0', 'adult_max_weight' => '3000.0',
        ])->assertOk();
    }

    private function adultFlock(Breed $breed): Flock
    {
        $entry = now(config('lots.timezone'))->subWeeks(59)->startOfDay();
        $flock = $this->flock(20, $breed);
        $flock->forceFill(['entry_date' => $entry->toDateString(), 'established_at' => $entry->utc()])->save();
        FlockMovement::factory()->create([
            'destination_flock_id' => $flock->id,
            'quantity' => 20,
            'occurred_at' => $entry->utc(),
            'created_by' => auth()->id(),
            'after' => [$flock->public_id => [
                'public_id' => $flock->public_id,
                'poultry_house_id' => $flock->poultry_house_id,
                'production_unit_id' => $flock->production_unit_id,
                'current_quantity' => 20,
                'entry_date' => $entry->toDateString(),
            ]],
        ]);

        return $flock->fresh();
    }
}
