<?php

namespace Tests\Feature\Inventory;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class EggStockPhysicalCountEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @param list<string> $permissions */
    private function signIn(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Sanctum::actingAs($user, ['api:access']);

        return $user;
    }

    /** @param array<string, mixed> $payload */
    private function requestCount(ProductionUnit $unit, array $payload, ?string $key = null): TestResponse
    {
        return $this->json('POST', "/api/v1/production-units/{$unit->id}/egg-stock/counts", $payload, [
            'Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    /** Registra un ingreso previo para establecer un saldo teórico conocido. */
    private function receipt(ProductionUnit $unit, int $quantity): string
    {
        return $this->json('POST', "/api/v1/production-units/{$unit->id}/egg-stock/receipts", [
            'quantity' => $quantity,
            'reason' => 'Saldo inicial de prueba',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.transaction');
    }

    /** Request: registra un excedente y conserva como referencia el saldo teórico previo. */
    public function test_positive_difference_creates_compensating_movement_and_preserves_prior_transaction(): void
    {
        // Preparación: autentica a quien ajusta y registra un saldo previo de diez huevos.
        $actor = $this->signIn(['egg-stock.adjust', 'egg-stock.move', 'egg-stock.view']);
        $unit = ProductionUnit::factory()->create();
        $previousTransaction = $this->receipt($unit, 10);

        // Request: cuenta cuatro huevos adicionales con fecha y motivo explícitos.
        $response = $this->requestCount($unit, [
            'counted_quantity' => 14,
            'expected_balance' => 10,
            'reason' => 'Recuento al cierre',
            'occurred_at' => '2026-09-28',
        ])->assertCreated();

        // Verificación: guarda la diferencia firmada y agrega un movimiento compensatorio.
        $countId = $response->json('data.id');
        $response->assertJsonPath('data.type', 'physical_count')
            ->assertJsonPath('data.quantity', 4)
            ->assertJsonPath('data.balance_before', 10)
            ->assertJsonPath('data.counted_quantity', 14)
            ->assertJsonPath('data.difference', 4)
            ->assertJsonPath('data.reason', 'Recuento al cierre')
            ->assertJsonPath('data.actor.id', $actor->id)
            ->assertJsonPath('data.actor.name', $actor->name);
        $this->assertDatabaseHas('egg_stock_transactions', ['public_id' => $previousTransaction, 'quantity' => 10]);
        $this->assertDatabaseHas('egg_stock_transactions', ['public_id' => $countId, 'type' => 'physical_count', 'difference' => 4]);
        $this->assertSame('4.000000', (string) DB::table('inventory_movement_lines')
            ->whereIn('inventory_movement_id', DB::table('inventory_movements')->where('reference_id', $countId)->select('id'))
            ->value('on_hand_delta'));
        $this->getJson("/api/v1/production-units/{$unit->id}/egg-stock")->assertJsonPath('data.balance', 14);
    }

    /** Request: registra faltante y mantiene su magnitud junto a la diferencia negativa. */
    public function test_negative_difference_records_shortage_direction_and_signed_delta(): void
    {
        // Preparación: fija saldo teórico y actor autorizado.
        $this->signIn(['egg-stock.adjust', 'egg-stock.move', 'egg-stock.view']);
        $unit = ProductionUnit::factory()->create();
        $this->receipt($unit, 10);

        // Request: informa que el conteo físico encontró seis huevos.
        $response = $this->requestCount($unit, [
            'counted_quantity' => 6,
            'expected_balance' => 10,
            'reason' => 'Faltante detectado',
            'occurred_at' => '2026-09-27',
        ])->assertCreated();

        // Verificación: la respuesta registra delta negativo y el libro aplica la salida.
        $countId = $response->json('data.id');
        $response->assertJsonPath('data.quantity', 4)
            ->assertJsonPath('data.balance_before', 10)
            ->assertJsonPath('data.counted_quantity', 6)
            ->assertJsonPath('data.difference', -4);
        $this->assertSame('-4.000000', (string) DB::table('inventory_movement_lines')
            ->whereIn('inventory_movement_id', DB::table('inventory_movements')->where('reference_id', $countId)->select('id'))
            ->value('on_hand_delta'));
        $this->getJson("/api/v1/production-units/{$unit->id}/egg-stock")->assertJsonPath('data.balance', 6);
    }

    /** Request: registra evidencia de conteo aunque la diferencia contra el saldo sea cero. */
    public function test_equal_count_records_audit_history_without_zero_quantity_movement(): void
    {
        // Preparación: genera saldo conocido y cuenta las operaciones existentes.
        $actor = $this->signIn(['egg-stock.adjust', 'egg-stock.move', 'egg-stock.view']);
        $unit = ProductionUnit::factory()->create();
        $this->receipt($unit, 10);
        $movementCount = DB::table('inventory_movements')->count();

        // Request: confirma que el conteo físico coincide con el saldo teórico.
        $response = $this->requestCount($unit, [
            'counted_quantity' => 10,
            'expected_balance' => 10,
            'reason' => 'Control sin diferencias',
            'occurred_at' => '2026-09-28',
        ])->assertCreated();

        // Verificación: conserva el conteo y la auditoría sin fabricar una línea de saldo cero.
        $response->assertJsonPath('data.quantity', 0)
            ->assertJsonPath('data.difference', 0)
            ->assertJsonPath('data.actor.id', $actor->id);
        $this->assertDatabaseCount('inventory_movements', $movementCount);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'egg_stock_physical_count_recorded',
            'causer_id' => $actor->id,
        ]);
    }

    /** Request: rechaza conteo negativo y cualquier actor provisto en el cuerpo. */
    public function test_validation_rejects_negative_count_and_client_supplied_actor(): void
    {
        // Preparación: crea la unidad y autentica a un usuario con permiso de conteo.
        $this->signIn(['egg-stock.adjust']);
        $unit = ProductionUnit::factory()->create();
        $base = [
            'counted_quantity' => 0,
            'expected_balance' => 0,
            'reason' => 'Verificación',
            'occurred_at' => '2026-09-28',
        ];

        // Requests: valida un valor negativo y luego una propiedad de actor no permitida.
        $this->requestCount($unit, [...$base, 'counted_quantity' => -1])->assertUnprocessable()
            ->assertJsonValidationErrors('counted_quantity');
        $this->requestCount($unit, [...$base, 'actor' => ['id' => 999, 'name' => 'Cliente']])->assertUnprocessable()
            ->assertJsonValidationErrors('actor');

        // Verificación: los dos payloads inválidos no dejan transacciones registradas.
        $this->assertDatabaseCount('egg_stock_transactions', 0);
    }

    /** Request: conserva el permiso actual de ajuste para la operación especializada. */
    public function test_user_without_adjust_permission_is_forbidden(): void
    {
        // Preparación: autentica a un usuario con acceso de consulta únicamente.
        $this->signIn(['egg-stock.view']);
        $unit = ProductionUnit::factory()->create();

        // Request: intenta ejecutar el conteo sin el permiso existente de ajuste.
        $this->requestCount($unit, [
            'counted_quantity' => 0,
            'expected_balance' => 0,
            'reason' => 'Conteo',
            'occurred_at' => '2026-09-28',
        ])->assertForbidden();

        // Verificación: el rechazo no crea auditoría ni una transacción.
        $this->assertDatabaseCount('egg_stock_transactions', 0);
    }

    /** Request: devuelve el mismo conteo en la lista y el detalle con el actor autenticado. */
    public function test_count_history_and_detail_include_date_reason_direction_quantity_and_actor(): void
    {
        // Preparación: registra saldo y captura la identidad de sesión.
        $actor = $this->signIn(['egg-stock.adjust', 'egg-stock.move', 'egg-stock.view']);
        $unit = ProductionUnit::factory()->create();
        $this->receipt($unit, 10);

        // Request: registra un conteo por debajo del saldo esperado.
        $created = $this->requestCount($unit, [
            'counted_quantity' => 8,
            'expected_balance' => 10,
            'reason' => 'Dos huevos dañados',
            'occurred_at' => '2026-09-26',
        ])->assertCreated();
        $countId = $created->json('data.id');

        // Requests: consulta el movimiento filtrado en la lista y su detalle.
        $listed = $this->getJson("/api/v1/production-units/{$unit->id}/egg-stock/movements?type=physical_count")
            ->assertOk()
            ->assertJsonPath('data.0.id', $countId)
            ->assertJsonPath('data.0.difference', -2)
            ->assertJsonPath('data.0.actor.id', $actor->id);
        $this->getJson("/api/v1/egg-stock/movements/{$countId}")
            ->assertOk()
            ->assertJsonPath('data.occurred_at', $created->json('data.occurred_at'))
            ->assertJsonPath('data.reason', 'Dos huevos dañados')
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.difference', -2)
            ->assertJsonPath('data.actor.id', $actor->id);

        // Verificación: el resultado de la lista conserva la fila de conteo.
        $this->assertSame(1, count($listed->json('data')));
    }

    /** Request: devuelve la operación original en reintentos y rechaza datos distintos con la misma clave. */
    public function test_idempotency_reuses_identical_count_and_rejects_conflicting_replay(): void
    {
        // Preparación: autentica al actor, establece saldo y fija una clave de idempotencia.
        $this->signIn(['egg-stock.adjust', 'egg-stock.move']);
        $unit = ProductionUnit::factory()->create();
        $this->receipt($unit, 10);
        $key = (string) Str::uuid();
        $payload = [
            'counted_quantity' => 11,
            'expected_balance' => 10,
            'reason' => 'Conteo repetible',
            'occurred_at' => '2026-09-28',
        ];

        // Requests: repite el comando exacto y luego cambia la cantidad usando la misma clave.
        $first = $this->requestCount($unit, $payload, $key)->assertCreated();
        $replay = $this->requestCount($unit, $payload, $key)->assertCreated();
        $this->requestCount($unit, [...$payload, 'counted_quantity' => 12], $key)
            ->assertStatus(409)
            ->assertJsonPath('message', 'La clave de idempotencia ya fue utilizada con otros datos.');

        // Verificación: sólo el comando original tiene registro y movimiento de conteo.
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertDatabaseCount('egg_stock_transactions', 2);
        $this->assertDatabaseCount('egg_stock_commands', 2);
        $this->assertDatabaseCount('inventory_movements', 2);
    }

    /** Request: detecta una vista obsoleta si otro movimiento cambia el saldo antes de confirmar. */
    public function test_stale_expected_balance_returns_conflict_without_recording_count(): void
    {
        // Preparación: el usuario consulta saldo diez y otro movimiento lo incrementa.
        $this->signIn(['egg-stock.adjust', 'egg-stock.move', 'egg-stock.view']);
        $unit = ProductionUnit::factory()->create();
        $this->receipt($unit, 10);
        $this->receipt($unit, 1);

        // Request: intenta confirmar usando el saldo visto antes del segundo ingreso.
        $this->requestCount($unit, [
            'counted_quantity' => 12,
            'expected_balance' => 10,
            'reason' => 'Conteo con vista anterior',
            'occurred_at' => '2026-09-28',
        ])->assertStatus(409)
            ->assertJsonPath('code', 'egg_stock_balance_changed')
            ->assertJsonPath('meta.current_balance', 11);

        // Verificación: el cambio ajeno permanece y el conteo fallido no se guarda.
        $this->assertDatabaseCount('egg_stock_transactions', 2);
        $this->getJson("/api/v1/production-units/{$unit->id}/egg-stock")->assertJsonPath('data.balance', 11);
    }

    /** Request: no permite editar ni cancelar un conteo como si fuera un movimiento previo. */
    public function test_historical_correction_and_cancellation_reject_physical_count_records(): void
    {
        // Preparación: crea un conteo válido que debe permanecer como registro independiente.
        $this->signIn(['egg-stock.adjust', 'egg-stock.move']);
        $unit = ProductionUnit::factory()->create();
        $countId = $this->requestCount($unit, [
            'counted_quantity' => 0,
            'expected_balance' => 0,
            'reason' => 'Saldo vacío',
            'occurred_at' => '2026-09-28',
        ])->assertCreated()->json('data.id');

        // Requests: intenta usar los endpoints versionados de corrección y cancelación.
        $this->json('PATCH', "/api/v1/egg-stock/movements/{$countId}", [
            'version' => 1,
            'correction_reason' => 'No corresponde',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409);
        $this->json('POST', "/api/v1/egg-stock/movements/{$countId}/cancellation", [
            'version' => 1,
            'correction_reason' => 'No corresponde',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409);

        // Verificación: el conteo conserva su cantidad y no acumula revisiones históricas.
        $this->assertDatabaseHas('egg_stock_transactions', ['public_id' => $countId, 'type' => 'physical_count', 'version' => 1]);
        $this->assertDatabaseCount('egg_stock_transaction_revisions', 0);
    }
}
