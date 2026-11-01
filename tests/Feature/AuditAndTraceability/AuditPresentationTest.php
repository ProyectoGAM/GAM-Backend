<?php

namespace Tests\Feature\AuditAndTraceability;

use App\Actions\Inventory\CorrectFeedConsumptionAction;
use App\Actions\Inventory\RecordDailyFeedConsumptionAction;
use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\Inventory\FeedRecipeStatus;
use App\Enums\Lots\FlockStatus;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\Lots\Breed;
use App\Models\Lots\Flock;
use App\Models\Lots\MortalityRecord;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Lots\LotsTestCase;

final class AuditPresentationTest extends LotsTestCase
{
    // Flujo: exige autenticación y permiso de auditoría en cada lectura nueva.
    public function test_reads_require_authentication_and_audit_permission(): void
    {
        // Preparación: crea una entrada consultable para aislar la autorización.
        $entry = $this->entry();

        // Request: los tres endpoints rechazan una sesión ausente.
        $this->getJson('/api/v1/audit/entries')->assertUnauthorized();
        $this->getJson($this->detailUrl($entry))->assertUnauthorized();
        $this->getJson('/api/v1/auditoria/responsables')->assertUnauthorized();

        // Request: una sesión sin permiso tampoco puede consultar nombres ni detalles.
        $this->signIn([]);
        $this->getJson('/api/v1/audit/entries')->assertForbidden();
        $this->getJson($this->detailUrl($entry))->assertForbidden();
        $this->getJson('/api/v1/auditoria/responsables')->assertForbidden();
    }

    // Flujo: conserva eventos desconocidos, totales y páginas sin cargar el detalle.
    public function test_unknown_events_have_server_labels_and_keep_pagination(): void
    {
        // Preparación: registra un evento nuevo para el cliente y otro sin descripción útil.
        $this->signIn(['audit.view']);
        $first = $this->entry(['event' => 'future_event', 'description' => 'Actividad futura registrada']);
        $this->entry(['event' => 'opaque_event', 'description' => 'opaque_event']);

        // Consulta: el listado conserva ambas entradas y sólo incorpora un resumen.
        $this->getJson('/api/v1/audit/entries?per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.presentation.title', 'Actividad registrada')
            ->assertJsonMissingPath('data.0.presentation.facts')->assertJsonMissingPath('data.0.presentation.actions');
        $this->getJson('/api/v1/audit/entries?event=future_event')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.presentation.title', 'Actividad futura registrada');

        // Consulta: el catálogo proporciona etiquetas sin necesitar un despliegue del cliente.
        $events = $this->getJson('/api/v1/reference/options')->assertOk()->json('data.audit.events');
        $this->assertContains(['value' => 'future_event', 'label' => 'Actividad futura registrada'], $events);
        $this->getJson($this->detailUrl($first))->assertOk()
            ->assertJsonPath('data.presentation.reversibility.state', 'unknown')
            ->assertJsonPath('data.presentation.changes_state', 'not_recorded');
    }

    // Flujo: distingue el nombre histórico del vigente y conserva cuentas dadas de baja.
    public function test_actor_snapshot_survives_rename_disable_and_permanent_removal(): void
    {
        // Preparación: registra la operación mediante el grabador compartido.
        $this->signIn(['audit.view']);
        $actor = User::factory()->create(['name' => 'Ana Original']);
        $subject = User::factory()->create();
        DB::transaction(fn () => $this->app->make(AuditRecorder::class)->record(AuditEntryData::forSubject(
            $subject, $actor, 'identity', 'user_registered', 'Usuario registrado',
            properties: ['subject_snapshot' => ['name' => $subject->name]],
        )));
        $entry = AuditEntry::query()->latest('id')->firstOrFail();
        $original = $entry->getRawOriginal('properties');

        // Mutación y consulta: el cambio del directorio no modifica el nombre histórico.
        $actor->update(['name' => 'Ana Actual']);
        $actor->delete();
        $this->getJson($this->detailUrl($entry))->assertOk()
            ->assertJsonPath('data.actor.name', 'Ana Original')->assertJsonPath('data.actor.current_name', 'Ana Actual')
            ->assertJsonPath('data.actor.status', 'disabled')->assertJsonPath('data.actor.exists', true)
            ->assertJsonPath('data.actor.can_manage', false)->assertJsonMissingPath('data.actor.email');

        // Mutación y consulta: una eliminación física no destruye la instantánea ni ofrece gestión.
        $actor->forceDelete();
        $this->getJson($this->detailUrl($entry))->assertOk()
            ->assertJsonPath('data.actor.name', 'Ana Original')->assertJsonPath('data.actor.current_name', null)
            ->assertJsonPath('data.actor.exists', false)->assertJsonPath('data.actor.can_manage', false);
        $this->assertSame($original, $entry->refresh()->getRawOriginal('properties'));
    }

    // Flujo: no inventa nombres históricos para entradas anteriores a esta entrega.
    public function test_legacy_actors_use_current_name_only_in_its_explicit_field(): void
    {
        // Preparación: registra el formato anterior sin instantánea del responsable.
        $this->signIn(['audit.view', 'identity.users.manage']);
        $actor = User::factory()->create(['name' => 'Nombre vigente']);
        $entry = $this->entry(['causer_type' => User::class, 'causer_id' => $actor->id]);

        // Consulta: diferencia la información disponible y la facultad actual de gestión.
        $this->getJson($this->detailUrl($entry))->assertOk()->assertJsonPath('data.actor.name', null)
            ->assertJsonPath('data.actor.current_name', 'Nombre vigente')->assertJsonPath('data.actor.can_manage', true);
    }

    // Flujo: pagina responsables históricos sin abrir el directorio completo de usuarios.
    public function test_actor_search_is_minimal_paginated_and_deduplicated(): void
    {
        // Preparación: registra dos hechos del mismo responsable y un usuario sin actividad.
        $this->signIn(['audit.view']);
        $actor = User::factory()->create(['name' => 'Ana%Segura']);
        User::factory()->create(['name' => 'AnaXSegura']);
        $this->entry(['causer_type' => User::class, 'causer_id' => $actor->id, 'properties' => ['actor_snapshot' => ['name' => 'Ana Histórica']]]);
        $this->entry(['causer_type' => User::class, 'causer_id' => $actor->id, 'properties' => ['actor_snapshot' => ['name' => 'Ana Histórica']]]);
        $actor->delete();
        $this->entry(['source' => 'scheduler']);
        $this->entry();

        // Consulta: conserva sistema y actor desconocido como opciones diferentes.
        $this->getJson('/api/v1/auditoria/responsables?por_pagina=1')->assertOk()->assertJsonPath('meta.total', 3);
        $response = $this->getJson('/api/v1/auditoria/responsables?buscar=%25')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.value', $actor->id)
            ->assertJsonPath('data.0.label', 'Ana Histórica')->assertJsonPath('data.0.status', 'disabled')
            ->assertJsonPath('data.0.can_manage', false);
        $this->assertSame(['value', 'label', 'type', 'kind', 'status', 'can_manage', 'exists', 'name', 'current_name'], array_keys($response->json('data.0')));
        $this->getJson('/api/v1/auditoria/responsables?buscar=Histórica')->assertOk()->assertJsonPath('meta.total', 1);

        // Consulta: los filtros especiales se aplican en servidor sin perder totales.
        $this->getJson('/api/v1/audit/entries?actor_kind=system')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.actor.name', 'Sistema');
        $this->getJson('/api/v1/audit/entries?actor_kind=unavailable')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.actor.name', null);
    }

    // Flujo: valida los límites de las nuevas búsquedas y de las páginas.
    public function test_invalid_filters_are_rejected_and_missing_details_return_not_found(): void
    {
        // Preparación: autentica un auditor.
        $this->signIn(['audit.view']);

        // Request: rechaza filtros fuera de contrato antes de ejecutar consultas.
        $this->getJson('/api/v1/auditoria/responsables?por_pagina=101')->assertUnprocessable()->assertJsonValidationErrors('por_pagina');
        $this->getJson('/api/v1/auditoria/responsables?buscar='.str_repeat('a', 101))->assertUnprocessable()->assertJsonValidationErrors('buscar');
        $this->getJson('/api/v1/audit/entries?actor_kind=admin')->assertUnprocessable()->assertJsonValidationErrors('actor_kind');
        $this->getJson('/api/v1/audit/entries?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->getJson('/api/v1/auditoria/entradas/999999')->assertNotFound();
    }

    // Flujo: una marca textual antigua no demuestra que el responsable haya sido el sistema.
    public function test_actor_kind_filters_require_explicit_automatic_evidence(): void
    {
        // Preparación: conserva una entrada sin actor con una marca de tipo incorrecto.
        $this->signIn(['audit.view']);
        $this->entry(['properties' => ['automatic_process' => 'true']]);

        // Consulta: el filtro del servidor coincide con la identidad presentada.
        $this->getJson('/api/v1/audit/entries?actor_kind=system')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/audit/entries?actor_kind=unavailable')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.actor.kind', 'unavailable');
        $this->getJson('/api/v1/auditoria/responsables?buscar=Sistema')->assertOk()->assertJsonPath('meta.total', 0);
    }

    // Flujo: presenta únicamente datos permitidos y distingue omisión de ausencia de cambios.
    public function test_detail_does_not_expose_nested_implementation_data_or_secrets(): void
    {
        // Preparación: simula una entrada histórica con campos anidados y técnicos.
        $this->signIn(['audit.view']);
        $entry = $this->entry([
            'properties' => [
                'subject_snapshot' => ['stage_snapshot' => ['grams_per_bird' => '60', 'hash' => 'nested-secret'], 'password' => 'secret-value'],
                'production_unit_snapshot' => ['id' => 1, 'name' => 'UP histórica', 'token' => 'opaque-secret'],
                'presentation_subject' => ['label' => 'Recurso histórico', 'resource' => 'arbitrary_resource', 'id' => ['secret' => 'opaque-secret']],
            ],
            'attribute_changes' => ['old' => ['internal_version' => 1], 'new' => ['internal_version' => 2, 'token' => 'token-secret']],
        ]);

        // Consulta: el endpoint nuevo no devuelve el contenido técnico del registro.
        $response = $this->getJson($this->detailUrl($entry))->assertOk()
            ->assertJsonMissingPath('data.properties')->assertJsonMissingPath('data.trace_id')
            ->assertJsonPath('data.presentation.facts.0.value', '0,06 kg')
            ->assertJsonPath('data.presentation.subject.resource', null)
            ->assertJsonPath('data.presentation.subject.id', null)
            ->assertJsonMissingPath('data.presentation.production_unit.token')
            ->assertJsonPath('data.presentation.changes', [])
            ->assertJsonPath('data.presentation.changes_state', 'unavailable')
            ->assertJsonPath('data.presentation.history.state', 'partial');
        $this->assertStringNotContainsString('nested-secret', $response->getContent());
        $this->assertStringNotContainsString('token-secret', $response->getContent());
        $this->assertStringNotContainsString('opaque-secret', $response->getContent());
        $this->assertStringNotContainsString('internal_version', $response->getContent());
    }

    /** @return array<string, array{string, string}> */
    public static function quantities(): array
    {
        return ['entera' => ['36000', '36 kg'], 'gramo' => ['36001', '36,001 kg'], 'máxima' => ['999999999999', '999999999,999 kg']];
    }

    // Flujo: convierte gramos exactamente y mantiene el día de negocio sin desplazarlo por zona horaria.
    #[DataProvider('quantities')]
    public function test_feed_quantities_and_business_dates_keep_precision(string $grams, string $display): void
    {
        // Preparación: conserva los datos originales de una jornada.
        $this->signIn(['audit.view']);
        $entry = $this->entry(['event' => 'feed_consumption_recorded', 'properties' => [
            'subject_snapshot' => ['quantity_grams' => $grams, 'consumption_date' => '2026-10-30', 'mode' => 'automatic', 'population' => 600, 'age_days' => 30],
            'presentation_snapshot' => ['subject' => ['label' => 'Inicial', 'resource' => 'flock', 'id' => (string) Str::ulid()], 'recipe_name' => 'Ración inicial'],
        ]]);

        // Consulta: verifica valores presentables sin operaciones de punto flotante.
        $response = $this->getJson($this->detailUrl($entry))->assertOk();
        $facts = collect($response->json('data.presentation.facts'))->pluck('value', 'label');
        $this->assertSame($display, $facts['Cantidad de ración']);
        $this->assertSame('30/10/2026', $facts['Fecha de consumo']);
        $this->assertSame('Automático', $facts['Modo de alimentación']);
        $this->assertSame('30', $facts['Edad en días']);
    }

    // Flujo: conserva nombres al registrar consumo real y explica cuándo una revisión reemplaza al original.
    public function test_real_consumption_keeps_historical_references_and_compensating_actions(): void
    {
        // Preparación: admite un lote con 600 aves y una etapa de 60 gramos.
        $this->travelTo(CarbonImmutable::parse('2026-10-29 12:00', 'America/Montevideo'));
        $actor = $this->signIn(['audit.view', 'flocks.manage']);
        $recipe = FeedRecipe::factory()->create(['name' => 'Ración inicial']);
        $selection = $this->createPublishedPlanSelection($actor, [[
            'type' => 'ration_change', 'title' => 'Etapa inicial', 'timing_kind' => 'day', 'start_day' => 1,
            'start_week' => null, 'feed_recipe_id' => $recipe->id, 'grams_per_bird' => 60,
            'catalog_type' => 'feed_recipe', 'catalog_id' => $recipe->id,
            'catalog_snapshot' => ['id' => $recipe->id, 'name' => $recipe->name],
        ]]);
        $house = PoultryHouse::factory()->create(['bird_capacity' => 600]);
        $flockId = $this->command('POST', '/flocks', [
            'code' => 'Inicial', 'breed_id' => Breed::factory()->create()->id, 'origin' => 'Cría de prueba',
            'poultry_house_id' => $house->id, 'initial_quantity' => 600, 'entry_date' => '2026-10-29', ...$selection,
        ])->assertCreated()->json('data.flock.id');
        $flock = Flock::query()->where('public_id', $flockId)->firstOrFail();
        $unitName = ProductionUnit::query()->findOrFail($flock->production_unit_id)->name;

        // Mutación: registra la jornada mediante la Action real y renombra las referencias después.
        $this->travelTo(CarbonImmutable::parse('2026-10-31 00:00', 'America/Montevideo'));
        $consumption = $this->app->make(RecordDailyFeedConsumptionAction::class)->execute($flock->id, '2026-10-30');
        $entry = AuditEntry::query()->where('event', 'feed_consumption_recorded')->firstOrFail();
        $recipe->forceFill(['name' => 'Receta renombrada'])->save();
        $recipe->forceFill(['status' => FeedRecipeStatus::Inactive])->save();
        $flock->update(['code' => 'Lote renombrado']);
        $flock->forceFill(['status' => FlockStatus::Finished, 'current_quantity' => 0, 'finalized_at' => now(), 'finalization_reason' => 'Fin del ciclo'])->save();
        ProductionUnit::query()->findOrFail($flock->production_unit_id)->update(['name' => 'UP renombrada']);

        // Consulta: las referencias originales permanecen y el destino usa el identificador público del lote.
        $response = $this->getJson($this->detailUrl($entry))->assertOk()
            ->assertJsonPath('data.actor.kind', 'system')
            ->assertJsonPath('data.presentation.subject.label', 'INICIAL')
            ->assertJsonPath('data.presentation.production_unit.name', $unitName)
            ->assertJsonPath('data.presentation.summary', 'Se registraron 36 kg para 600 aves del lote INICIAL.')
            ->assertJsonPath('data.presentation.actions.0.target.flock_id', $flock->public_id)
            ->assertJsonPath('data.presentation.actions.0.available', true);
        $this->assertContains(['label' => 'Receta', 'value' => 'Ración inicial'], $response->json('data.presentation.facts'));

        // Mutación: agrega una compensación sin alterar el consumo original.
        $original = $entry->getRawOriginal('properties');
        $this->app->make(CorrectFeedConsumptionAction::class)->execute($consumption, [
            'idempotency_key' => (string) Str::uuid(), 'cantidad_g' => '35000', 'motivo' => 'Corrección de lectura',
        ], $actor);
        $correction = AuditEntry::query()->where('event', 'feed_consumption_corrected')->firstOrFail();

        // Consulta: el original explica el bloqueo y la revisión conserva el antes histórico.
        $this->getJson($this->detailUrl($entry))->assertOk()
            ->assertJsonPath('data.presentation.reversibility.state', 'unavailable')
            ->assertJsonPath('data.presentation.actions.0.available', false);
        $correctionResponse = $this->getJson($this->detailUrl($correction))->assertOk()
            ->assertJsonPath('data.presentation.actions.0.available', true);
        $this->assertContains(['label' => 'Receta', 'before' => 'Ración inicial', 'after' => 'Receta renombrada'], $correctionResponse->json('data.presentation.changes'));
        $this->assertSame($original, $entry->refresh()->getRawOriginal('properties'));
    }

    // Flujo: una reversión anunciada se revalida al confirmar y su compensación bloquea nuevas reversiones.
    public function test_inventory_actions_follow_current_stock_permissions_and_reversal_state(): void
    {
        // Preparación: registra un ingreso real con producto y ubicación compatibles.
        $actor = $this->signIn(['audit.view', 'inventory.move', 'inventory.adjust']);
        $product = Product::factory()->create();
        $location = StockLocation::factory()->create();
        $supplier = Supplier::factory()->create();
        $movementId = $this->postJson('/api/v1/inventory/receipts', [
            'supplier_id' => $supplier->id,
            'lines' => [['product_id' => $product->id, 'stock_location_id' => $location->id, 'quantity' => '10']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $entry = AuditEntry::query()->where('event', 'inventory_movement_recorded')->firstOrFail();
        $count = AuditEntry::query()->count();

        // Consulta: abrir el detalle sólo calcula elegibilidad y presenta los nombres de las líneas.
        $response = $this->getJson($this->detailUrl($entry))->assertOk()->assertJsonPath('data.presentation.actions.0.available', true);
        $this->assertStringContainsString($product->name, $response->json('data.presentation.changes.0.label'));
        $this->assertSame($count, AuditEntry::query()->count());

        // Mutación: otra operación consume el saldo después de abrir el detalle.
        $this->postJson('/api/v1/inventory/issues', [
            'lines' => [['product_id' => $product->id, 'stock_location_id' => $location->id, 'quantity' => '2']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        // Request: el comando vuelve a comprobar stock y rechaza la respuesta que ya quedó desactualizada.
        $this->postJson('/api/v1/inventory/movements/'.$movementId.'/reversals', ['reason' => 'Reversión solicitada'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->getJson($this->detailUrl($entry))->assertOk()->assertJsonPath('data.presentation.actions.0.available', false)
            ->assertJsonPath('data.presentation.actions.0.reason', 'El stock actual no alcanza para compensar el movimiento.');

        // Mutación: restaura saldo y ejecuta la compensación válida.
        StockBalance::query()->where('product_id', $product->id)->update(['on_hand_quantity' => '10']);
        $this->postJson('/api/v1/inventory/movements/'.$movementId.'/reversals', ['reason' => 'Ingreso duplicado'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->getJson($this->detailUrl($entry))->assertOk()->assertJsonPath('data.presentation.actions.0.reason', 'El movimiento ya fue revertido.');

        // Consulta: un auditor sin gestión recibe una explicación de permisos.
        $this->signIn(['audit.view']);
        $this->getJson($this->detailUrl($entry))->assertOk()->assertJsonPath('data.presentation.actions.0.reason', 'No tienes permiso para revertir movimientos de inventario.');
    }

    // Flujo: el número de consultas del listado no crece con sus responsables ni calcula acciones.
    public function test_list_actor_resolution_is_batched(): void
    {
        // Preparación: crea entradas con responsables distintos y mide sólo la lectura HTTP.
        $this->signIn(['audit.view']);
        foreach (User::factory()->count(12)->create() as $actor) {
            $this->entry(['causer_type' => User::class, 'causer_id' => $actor->id]);
        }
        DB::enableQueryLog();

        // Consulta: compara la misma página con uno y doce elementos.
        $this->getJson('/api/v1/audit/entries?per_page=1')->assertOk();
        $small = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->getJson('/api/v1/audit/entries?per_page=12')->assertOk()->assertJsonCount(12, 'data');
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($small, $large);
    }

    /** @return array<string, array{string, string}> */
    public static function specializedMovements(): array
    {
        return [
            'regularización' => ['stock_regularization', 'Una regularización no puede revertirse reintroduciendo stock incompatible.'],
            'elaboración' => ['feed_manufacture', 'Los movimientos de alimento deben corregirse desde su operación de elaboración o ajuste.'],
            'sanidad' => ['medicine_application', 'Los consumos sanitarios deben corregirse desde su aplicación.'],
        ];
    }

    // Flujo: deriva al módulo dueño de una operación y nunca anuncia una reversión genérica prohibida.
    #[DataProvider('specializedMovements')]
    public function test_specialized_movements_explain_the_current_restriction(string $reference, string $reason): void
    {
        // Preparación: conserva un movimiento histórico cuya operación tiene corrección especializada.
        $this->signIn(['audit.view', 'inventory.adjust']);
        $movement = InventoryMovement::factory()->create(['reference_type' => $reference]);
        $entry = $this->entry(['event' => 'inventory_movement_recorded', 'subject_type' => $movement->getMorphClass(), 'subject_id' => $movement->id]);

        // Consulta: la acción queda bloqueada con una explicación del módulo.
        $this->getJson($this->detailUrl($entry))->assertOk()
            ->assertJsonPath('data.presentation.reversibility.state', 'unavailable')
            ->assertJsonPath('data.presentation.actions.0.available', false)
            ->assertJsonPath('data.presentation.actions.0.reason', $reason);
    }

    /** @return array<string, array{string, list<array<string, int|string>>, array<string, int|string>}> */
    public static function weighingModes(): array
    {
        return [
            'individual' => ['individual', [['weight' => '20.0']], ['mode' => 'individual', 'weight' => '20.0']],
            'grupal' => ['group', [['bird_count' => 3, 'total_weight' => '60.0']], ['mode' => 'group', 'bird_count' => 3, 'total_weight' => '60.0']],
        ];
    }

    // Flujo: los pesajes convencionales y diarios entregan el lote público en el resumen y el detalle.
    #[DataProvider('weighingModes')]
    public function test_weighing_records_expose_the_flock_link(string $mode, array $measurements, array $dailyPayload): void
    {
        // Preparación: crea un lote con admisión y un usuario que puede pesarlo y consultar su auditoría.
        $this->signIn(['audit.view', 'weighings.manage', 'flocks.view']);
        $flock = $this->flockWithHistory(20);

        // Request: registra ambas APIs de pesaje mediante sus comandos reales.
        $this->command('POST', '/pesajes', [
            'flock_id' => $flock->public_id, 'mode' => $mode, 'unit' => 'g',
            'measurements' => $measurements, 'confirm_out_of_range' => true,
        ])->assertCreated();
        $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', [
            ...$dailyPayload, 'confirm_out_of_range' => true,
        ])->assertCreated();

        // Mutación: el nombre vigente no debe reemplazar una etiqueta histórica que nunca se guardó.
        $flock->update(['code' => 'LOTE-RENOMBRADO']);

        // Consulta: ambas entradas permiten enlazar al lote sin interpretar el JSON técnico ni el ID del pesaje.
        foreach (['weighing_recorded', 'daily_weighing_entry_added'] as $event) {
            $entry = AuditEntry::query()->where('event', $event)->firstOrFail();
            $subject = ['label' => null, 'resource' => 'flock', 'id' => $flock->public_id];
            $this->getJson('/api/v1/audit/entries?event='.$event)->assertOk()
                ->assertJsonPath('data.0.presentation.subject', $subject);
            $this->getJson($this->detailUrl($entry))->assertOk()
                ->assertJsonPath('data.presentation.subject', $subject)
                ->assertJsonPath('data.presentation.changes_state', 'presented');
        }
        $this->getJson('/api/v1/flocks/'.$flock->public_id)->assertOk();
    }

    /** @return array<string, array{string, string, string}> */
    public static function weighingTitles(): array
    {
        return [
            'individual' => ['weighing_recorded', 'individual', 'Pesaje individual registrado'],
            'grupal' => ['weighing_recorded', 'group', 'Pesaje grupal registrado'],
            'rectificación' => ['weighing_corrected', 'group', 'Pesaje grupal rectificado'],
        ];
    }

    // Flujo: presenta la modalidad y los pesos normalizados sin perder precisión ni exponer metadatos.
    #[DataProvider('weighingTitles')]
    public function test_weighing_presentation_describes_the_sample_and_expected_range(string $event, string $mode, string $title): void
    {
        // Preparación: conserva una muestra histórica de cinco aves dentro de su rango original.
        $this->signIn(['audit.view']);
        $snapshot = [
            'flock_id' => (string) Str::ulid(), 'mode' => $mode, 'represented_bird_count' => 5,
            'total_weight_g' => '2500.0', 'average_weight_g' => '500.000000', 'outside_expected_range' => false,
            'expected_range' => ['min_weight_g' => '400.0', 'max_weight_g' => '600.0', 'reference_version' => 8],
            'measurements' => $mode === 'individual'
                ? array_fill(0, 5, ['weight_g' => '500.0', 'token' => 'pesaje-secreto'])
                : [['bird_count' => 5, 'total_weight_g' => '2500.0', 'average_weight_g' => '500.000000', 'token' => 'pesaje-secreto']],
            'version' => 2,
        ];
        $entry = $this->entry(['event' => $event, 'properties' => ['subject_snapshot' => $snapshot], 'attribute_changes' => ['old' => [], 'new' => $snapshot]]);

        // Consulta: el resumen y los hechos describen negocio, sin el aviso genérico de cambios opacos.
        $this->getJson('/api/v1/audit/entries?event='.$event)->assertOk()
            ->assertJsonPath('data.0.presentation.title', $title)
            ->assertJsonPath('data.0.presentation.summary', '5 aves · Peso total: 2.500 g · Promedio: 500 g por ave. Dentro del rango esperado.');
        $response = $this->getJson($this->detailUrl($entry))->assertOk()
            ->assertJsonPath('data.presentation.changes_state', 'presented');
        $facts = collect($response->json('data.presentation.facts'))->pluck('value', 'label');
        $this->assertSame($mode === 'group' ? 'Grupal' : 'Individual', $facts['Modalidad']);
        $this->assertSame('5', $facts['Cantidad de aves']);
        $this->assertSame('2.500 g', $facts['Peso total']);
        $this->assertSame('500 g por ave', $facts['Promedio por ave']);
        $this->assertSame('400 g – 600 g por ave', $facts['Rango esperado por ave']);
        $this->assertStringNotContainsString('pesaje-secreto', $response->getContent());
        $this->assertStringNotContainsString('Los cambios registrados no tienen', $response->getContent());
        $this->assertStringNotContainsString('otros datos del registro no se muestran', $response->getContent());
    }

    // Flujo: no confunde una referencia ausente con la confirmación de un peso dentro de rango.
    public function test_weighing_without_reference_preserves_fractional_grams_and_explains_the_limit(): void
    {
        // Preparación: simula un pesaje antiguo registrado sin configuración de rangos.
        $this->signIn(['audit.view']);
        $snapshot = ['mode' => 'group', 'represented_bird_count' => 3, 'total_weight_g' => '500.0', 'average_weight_g' => '166.666667', 'expected_range' => null, 'outside_expected_range' => false];
        $entry = $this->entry(['event' => 'weighing_recorded', 'properties' => ['subject_snapshot' => $snapshot]]);

        // Consulta: conserva todos los decimales almacenados y explica que falta la referencia.
        $response = $this->getJson($this->detailUrl($entry))->assertOk();
        $facts = collect($response->json('data.presentation.facts'))->pluck('value', 'label');
        $this->assertSame('166,666667 g por ave', $facts['Promedio por ave']);
        $this->assertSame('Sin rango esperado disponible.', $facts['Evaluación del rango']);
        $this->assertArrayNotHasKey('Rango esperado por ave', $facts->all());
        $this->assertStringNotContainsString('Dentro del rango esperado.', $response->getContent());
    }

    // Flujo: conserva la evaluación de cada medición aunque el promedio general esté dentro del rango.
    public function test_weighing_range_result_does_not_hide_outliers_behind_the_average(): void
    {
        // Preparación: dos pesos fuera del rango tienen un promedio que sí caería dentro.
        $this->signIn(['audit.view']);
        $snapshot = ['mode' => 'individual', 'represented_bird_count' => 2, 'total_weight_g' => '1000.0',
            'average_weight_g' => '500.000000', 'outside_expected_range' => true,
            'expected_range' => ['min_weight_g' => '400.0', 'max_weight_g' => '600.0'],
            'measurements' => [['weight_g' => '100.0'], ['weight_g' => '900.0']]];
        $entry = $this->entry(['event' => 'weighing_recorded', 'properties' => ['subject_snapshot' => $snapshot]]);

        // Consulta: presenta el resultado histórico por medición, sin recalcularlo usando sólo el promedio.
        $response = $this->getJson($this->detailUrl($entry))->assertOk();
        $facts = collect($response->json('data.presentation.facts'))->pluck('value', 'label');
        $this->assertSame('Una o más mediciones fuera del rango esperado.', $facts['Evaluación del rango']);
        $this->assertSame('1 ave · 100 g', $facts['Medición 1']);
        $this->assertSame('1 ave · 900 g', $facts['Medición 2']);
        $this->assertStringNotContainsString('Dentro del rango esperado.', $response->getContent());
    }

    // Flujo: explica las transiciones reales de la muestra diaria al agregar, borrar y vaciar ingresos.
    public function test_daily_weighing_audit_shows_sample_totals_before_and_after_each_operation(): void
    {
        // Preparación: crea una jornada real con un ingreso individual y otro grupal.
        $this->signIn(['audit.view', 'weighings.manage']);
        $flock = $this->flockWithHistory(20);
        $first = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'individual', 'weight' => '20.0', 'confirm_out_of_range' => true])->assertCreated();
        $second = $this->command('POST', '/lotes/'.$flock->public_id.'/pesajes-diarios/ingresos', ['mode' => 'group', 'bird_count' => 3, 'total_weight' => '90.0', 'confirm_out_of_range' => true])->assertCreated();
        $added = AuditEntry::query()->where('event', 'daily_weighing_entry_added')->latest('id')->firstOrFail();

        // Consulta: distingue los datos del ingreso de los cuatro animales que ahora componen la jornada.
        $response = $this->getJson($this->detailUrl($added))->assertOk()
            ->assertJsonPath('data.presentation.title', 'Ingreso grupal de pesaje diario registrado')
            ->assertJsonPath('data.presentation.changes_state', 'presented');
        $facts = collect($response->json('data.presentation.facts'))->pluck('value', 'label');
        $this->assertSame('3', $facts['Aves del ingreso']);
        $this->assertSame('4', $facts['Cantidad de aves de la muestra diaria']);
        $this->assertSame('110 g', $facts['Peso total de la muestra diaria']);
        $this->assertSame('27,5 g por ave', $facts['Promedio de la muestra diaria']);
        $this->assertContains(['label' => 'Cantidad de aves de la muestra diaria', 'before' => '1', 'after' => '4'], $response->json('data.presentation.changes'));
        $this->assertContains(['label' => 'Peso total de la muestra diaria', 'before' => '20 g', 'after' => '110 g'], $response->json('data.presentation.changes'));

        // Mutación: elimina el grupo conservando su snapshot y la versión de la jornada.
        $dailyId = $second->json('data.daily_weighing.id');
        $deleted = $this->command('DELETE', '/pesajes-diarios/'.$dailyId.'/ingresos/'.$second->json('data.entry.id'), ['version' => $second->json('data.daily_weighing.version'), 'reason' => 'Grupo duplicado'])->assertOk();
        $removed = AuditEntry::query()->where('event', 'daily_weighing_entry_deleted')->latest('id')->firstOrFail();

        // Consulta: explica la disminución sin describirla como pérdida de datos o de historia.
        $response = $this->getJson($this->detailUrl($removed))->assertOk()
            ->assertJsonPath('data.presentation.title', 'Ingreso grupal de pesaje diario eliminado');
        $this->assertContains(['label' => 'Promedio de la muestra diaria', 'before' => '27,5 g por ave', 'after' => '20 g por ave'], $response->json('data.presentation.changes'));
        $this->assertContains(['label' => 'Motivo de eliminación', 'value' => 'Grupo duplicado'], $response->json('data.presentation.facts'));

        // Mutación y consulta: al eliminar el último ingreso el promedio queda sin aves, nunca se inventa cero.
        $this->command('DELETE', '/pesajes-diarios/'.$dailyId.'/ingresos/'.$first->json('data.entry.id'), ['version' => $deleted->json('data.daily_weighing.version'), 'reason' => 'Ingreso incorrecto'])->assertOk();
        $empty = AuditEntry::query()->where('event', 'daily_weighing_entry_deleted')->latest('id')->firstOrFail();
        $response = $this->getJson($this->detailUrl($empty))->assertOk();
        $this->assertStringContainsString('La muestra diaria quedó vacía.', $response->json('data.presentation.summary'));
        $this->assertContains(['label' => 'Promedio de la muestra diaria', 'before' => '20 g por ave', 'after' => 'Sin aves en la muestra'], $response->json('data.presentation.changes'));
    }

    /** @return array<string, array{string, bool}> */
    public static function weighingHistoryEvents(): array
    {
        return [
            'registro convencional' => ['weighing_recorded', false],
            'corrección convencional' => ['weighing_corrected', false],
            'ingreso diario' => ['daily_weighing_entry_added', true],
            'eliminación diaria' => ['daily_weighing_entry_deleted', true],
        ];
    }

    // Flujo: recupera el lote capturado en registros anteriores sin consultar el recurso ni reescribir el historial.
    #[DataProvider('weighingHistoryEvents')]
    public function test_historical_weighing_relations_use_the_captured_public_id(string $event, bool $nested): void
    {
        // Preparación: conserva el formato histórico y una relación anterior distinta para detectar confusiones.
        $this->signIn(['audit.view']);
        $publicId = (string) Str::ulid();
        $snapshot = $nested ? ['daily_weighing' => ['flock_id' => $publicId]] : ['flock_id' => $publicId];
        $entry = $this->entry([
            'event' => $event, 'properties' => ['subject_snapshot' => $snapshot],
            'attribute_changes' => ['old' => ['flock_id' => (string) Str::ulid()], 'new' => $snapshot],
        ]);
        $original = $entry->getRawOriginal('properties');

        // Consulta: la presentación usa el lote del evento aunque no exista una fila vigente que consultar.
        $this->getJson($this->detailUrl($entry))->assertOk()
            ->assertJsonPath('data.presentation.subject', ['label' => null, 'resource' => 'flock', 'id' => $publicId]);
        $this->assertSame($original, $entry->refresh()->getRawOriginal('properties'));
    }

    /** @return array<string, array{mixed}> */
    public static function invalidWeighingFlockIds(): array
    {
        return ['ausente' => [null], 'interno' => [42], 'interno textual' => ['42'], 'inválido' => ['identificador-invalido'], 'opaco' => [['secret' => 'no-mostrar']]];
    }

    // Flujo: una referencia ausente, interna o inválida no genera un enlace al lote.
    #[DataProvider('invalidWeighingFlockIds')]
    public function test_invalid_weighing_relations_do_not_become_navigation_targets(mixed $flockId): void
    {
        // Preparación: representa datos antiguos que no contienen un identificador público utilizable.
        $this->signIn(['audit.view']);
        $entry = $this->entry(['event' => 'weighing_recorded', 'properties' => ['subject_snapshot' => ['flock_id' => $flockId]]]);

        // Consulta: conserva la entrada con relación desconocida sin exponer contenido opaco.
        $response = $this->getJson($this->detailUrl($entry))->assertOk()
            ->assertJsonPath('data.presentation.subject.resource', null)
            ->assertJsonPath('data.presentation.subject.id', null);
        $this->assertStringNotContainsString('no-mostrar', $response->getContent());
    }

    // Flujo: el contrato publicado describe los campos reales del resumen, detalle y actor.
    public function test_openapi_matches_the_delivered_response_shapes(): void
    {
        // Preparación: carga el contrato que consumirá el frontend.
        $this->signIn(['audit.view']);
        $entry = $this->entry();
        $contract = Yaml::parseFile(base_path('contracts/openapi/audit.yaml'));

        // Consulta: contrasta los campos públicos con los obligatorios de OpenAPI.
        $detail = $this->getJson($this->detailUrl($entry))->assertOk()->json('data');
        $list = $this->getJson('/api/v1/audit/entries')->assertOk()->json('data.0');
        $this->assertEqualsCanonicalizing($contract['components']['schemas']['DetailEntry']['required'], array_keys($detail));
        $this->assertEqualsCanonicalizing($contract['components']['schemas']['Summary']['required'], array_keys($list['presentation']));
        $this->assertEqualsCanonicalizing($contract['components']['schemas']['Actor']['required'], array_keys($detail['actor']));
        $this->assertSame(100, $contract['paths']['/auditoria/responsables']['get']['parameters'][1]['schema']['maximum']);
    }

    // Flujo: enlaza la mortalidad y su efecto en el censo; deshacer siempre usa el comando existente.
    public function test_mortality_history_links_its_flock_and_exposes_current_undo_confirmation(): void
    {
        // Preparación: registra cinco bajas mediante el flujo de negocio.
        $this->signIn(['audit.view', 'flocks.view', 'mortality.view', 'mortality.manage']);
        $flock = $this->flock();
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 5, 'notes' => 'Recuento de prueba', 'occurred_at' => now()->startOfSecond()->toIso8601String(),
        ])->assertCreated();
        $recordId = $created->json('data.mortality.id');
        $entries = AuditEntry::query()->whereIn('event', ['mortality_recorded', 'flock_mortality_applied'])->get();
        $this->assertCount(2, $entries);

        // Consulta: ambos eventos llevan al mismo registro público sin ejecutar una cancelación.
        foreach ($entries as $entry) {
            $this->getJson($this->detailUrl($entry))->assertOk()
                ->assertJsonPath('data.presentation.subject.resource', 'flock')
                ->assertJsonPath('data.presentation.subject.id', $flock->public_id)
                ->assertJsonPath('data.presentation.actions.0.kind', 'undo_mortality')
                ->assertJsonPath('data.presentation.actions.0.available', true)
                ->assertJsonPath('data.presentation.actions.0.target.id', $recordId)
                ->assertJsonPath('data.presentation.actions.0.target.flock_id', $flock->public_id);
        }
        $this->assertSame(95, $flock->refresh()->current_quantity);

        // Mutación: el comando existente devuelve las aves y conserva la auditoría.
        $this->command('POST', "/mortalities/{$recordId}/cancellation", [
            'version' => 1, 'flock_version' => 2, 'reason' => 'Anotación duplicada',
        ])->assertOk()->assertJsonPath('data.flock.current_quantity', 100);
        $cancelled = AuditEntry::query()->where('event', 'mortality_cancelled')->firstOrFail();

        // Consulta: la entrada antigua y la cancelación mantienen el lote y bloquean otro deshacer.
        foreach ([$entries->first(), $cancelled] as $entry) {
            $this->getJson($this->detailUrl($entry))->assertOk()
                ->assertJsonPath('data.presentation.subject.id', $flock->public_id)
                ->assertJsonPath('data.presentation.actions.0.available', false)
                ->assertJsonPath('data.presentation.actions.0.reason', 'Este registro de mortalidad ya fue deshecho.');
        }
    }

    // Flujo: no anuncia deshacer sin permisos, sobre revisiones viejas o con recursos ausentes.
    public function test_mortality_undo_revalidates_permission_version_and_existence(): void
    {
        // Preparación: conserva una referencia histórica y un registro vigente.
        $user = $this->signIn(['audit.view']);
        $flock = $this->flock();
        $record = MortalityRecord::factory()->create(['flock_id' => $flock->id]);
        $entry = $this->entry(['event' => 'mortality_recorded', 'log_name' => 'lots',
            'subject_type' => $record->getMorphClass(), 'subject_id' => $record->id,
            'properties' => ['subject_snapshot' => ['flock_id' => $flock->public_id, 'version' => 1]]]);

        // Consulta: un lector puede consultar el contexto, pero no deshacer.
        $this->getJson($this->detailUrl($entry))->assertOk()->assertJsonPath('data.presentation.actions.0.available', false);
        foreach (['flocks.view', 'mortality.view', 'mortality.manage'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->getJson($this->detailUrl($entry))->assertOk()->assertJsonPath('data.presentation.actions.0.available', true);

        // Mutación y consulta: una nueva versión invalida la acción de la entrada anterior.
        $record->forceFill(['version' => 2])->save();
        $this->getJson($this->detailUrl($entry))->assertOk()->assertJsonPath('data.presentation.actions.0.available', false);

        // Mutación y consulta: un recurso eliminado conserva el contexto histórico sin destino ejecutable.
        $record->delete();
        $this->getJson($this->detailUrl($entry))->assertOk()
            ->assertJsonPath('data.presentation.subject.id', $flock->public_id)
            ->assertJsonPath('data.presentation.reversibility.state', 'unavailable')
            ->assertJsonPath('data.presentation.actions', []);
    }

    /** @param array<string, mixed> $attributes */
    private function entry(array $attributes = []): AuditEntry
    {
        return AuditEntry::query()->create([
            'log_name' => 'test', 'event' => 'test_event', 'description' => 'Actividad de prueba',
            'source' => 'api', 'properties' => [], ...$attributes,
        ]);
    }

    private function detailUrl(AuditEntry $entry): string
    {
        return '/api/v1/auditoria/entradas/'.$entry->id;
    }
}
