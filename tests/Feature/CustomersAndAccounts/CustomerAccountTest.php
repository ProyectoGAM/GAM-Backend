<?php

namespace Tests\Feature\CustomersAndAccounts;

use App\Actions\CustomersAndAccounts\AdjustCustomerAccountAction;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class CustomerAccountTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function customer(): Customer
    {
        $this->seed(IdentityPermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');
        Sanctum::actingAs($user, ['api:access']);

        return Customer::factory()->withAccount()->create(['created_by' => $user->id]);
    }

    private function command(Customer $customer, string $path, array $data, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/clientes/'.$customer->public_id.'/'.$path, $data, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    /** El ajuste conserva precisión, motivo y ambos saldos sin simular un pago. */
    public function test_adjustment_and_payment_keep_three_decimals_and_separate_audits(): void
    {
        // Alta de cuenta y aumento exacto.
        $customer = $this->customer();
        $this->command($customer, 'ajustes', ['direction' => 'increase', 'amount' => '12.625', 'reason' => 'Saldo anterior', 'expected_version' => 0])
            ->assertOk()->assertJsonPath('data.0.balance_after', '12.625');
        // Cobro real separado del ajuste.
        $this->command($customer, 'pagos', ['amount' => '2.125', 'payment_method' => 'cash'])->assertOk()->assertJsonPath('data.0.amount', '-2.125');
        $this->getJson('/api/v1/clientes/'.$customer->public_id.'/cuenta-corriente')->assertOk()->assertJsonPath('data.balance', '10.500')->assertJsonPath('data.version', 2);
        $this->assertDatabaseCount('customer_account_movements', 2);
        $this->assertSame(2, AuditEntry::query()->where('log_name', 'customers_and_accounts')->count());
    }

    /** Un reintento resuelve su identidad antes de consultar una versión ya cambiada. */
    public function test_replays_adjustment_before_version_check_and_conflicts_on_changed_payload(): void
    {
        // Primera operación y segundo intento de la misma clave.
        $customer = $this->customer();
        $key = (string) Str::uuid();
        $data = ['direction' => 'increase', 'amount' => '100', 'reason' => 'Apertura', 'expected_version' => 0];
        $id = $this->command($customer, 'ajustes', $data, $key)->assertOk()->json('data.0.id');
        $this->command($customer, 'ajustes', $data, $key)->assertOk()->assertJsonPath('data.0.id', $id);
        $this->command($customer, 'ajustes', [...$data, 'amount' => '101'], $key)->assertConflict();
        $this->command($customer, 'ajustes', $data)->assertConflict();
        $this->assertDatabaseCount('customer_account_movements', 1);
    }

    /** La disminución registra saldo a favor sin recortar ni perder dinero. */
    public function test_credit_balance_is_preserved_and_reported_separately(): void
    {
        // Un pago excedente se conserva íntegro en la cuenta.
        $customer = $this->customer();
        $this->command($customer, 'pagos', ['amount' => '20.001', 'payment_method' => 'transfer'])->assertOk();
        $this->getJson('/api/v1/clientes/'.$customer->public_id.'/cuenta-corriente')->assertOk()
            ->assertJsonPath('data.balance', '-20.001')->assertJsonPath('data.credit', '20.001')->assertJsonPath('data.debt', '0.000');
    }

    /** La reversión agrega una compensación y nunca modifica ni duplica el original. */
    public function test_reverses_payment_once_and_keeps_original_immutable(): void
    {
        // Pago original y compensación autorizada.
        $customer = $this->customer();
        $id = $this->command($customer, 'pagos', ['amount' => '30.125', 'payment_method' => 'cash'])->assertOk()->json('data.0.id');
        $this->command($customer, 'movimientos/'.$id.'/reversion', ['reason' => 'Cobro ingresado dos veces'])->assertOk()->assertJsonPath('data.0.amount', '30.125');
        $this->command($customer, 'movimientos/'.$id.'/reversion', ['reason' => 'Otro intento'])->assertConflict();
        $this->assertDatabaseHas('customer_account_movements', ['public_id' => $id, 'amount' => '-30.125']);
        $this->assertDatabaseHas('customer_accounts', ['customer_id' => $customer->id, 'balance' => '0.000']);
        $this->assertDatabaseCount('customer_account_movements', 2);
    }

    /** Un movimiento de otra cuenta no puede revertirse desde una ficha ajena. */
    public function test_foreign_account_movement_is_not_found(): void
    {
        // Dos cuentas independientes y referencia cruzada inválida.
        $customer = $this->customer();
        $other = Customer::factory()->withAccount()->create();
        $id = $this->command($customer, 'pagos', ['amount' => '1', 'payment_method' => 'cash'])->assertOk()->json('data.0.id');
        $this->command($other, 'movimientos/'.$id.'/reversion', ['reason' => 'No corresponde'])->assertNotFound();
        $this->assertDatabaseCount('customer_account_movements', 1);
    }

    /** Los ajustes inválidos no reservan una clave ni alteran el saldo. */
    public function test_invalid_adjustments_are_rejected_without_side_effects(): void
    {
        // Valores inválidos y motivo compuesto sólo por espacios.
        $customer = $this->customer();
        foreach (['0', '-1', '1.0001', '1e3'] as $amount) {
            $this->command($customer, 'ajustes', ['direction' => 'decrease', 'amount' => $amount, 'reason' => 'Revisión', 'expected_version' => 0])->assertUnprocessable();
        }
        $this->command($customer, 'ajustes', ['direction' => 'increase', 'amount' => '1', 'reason' => '  ', 'expected_version' => 0])->assertUnprocessable();
        $this->assertDatabaseCount('customer_operations', 0);
        $this->assertDatabaseCount('customer_account_movements', 0);
    }

    /** Incluso con permiso financiero, una persona sin rol admin no puede ajustar desde HTTP. */
    public function test_non_admin_cannot_adjust_even_with_permission(): void
    {
        // Permiso explícito sin rol administrativo.
        $customer = $this->customer();
        $user = User::factory()->create();
        $user->givePermissionTo('customer-accounts.adjust');
        Sanctum::actingAs($user, ['api:access']);
        $this->command($customer, 'ajustes', ['direction' => 'increase', 'amount' => '1', 'reason' => 'Intento', 'expected_version' => 0])->assertForbidden();
        $this->assertDatabaseCount('customer_account_movements', 0);
    }

    /** La Action protege la misma restricción al invocarse sin el FormRequest. */
    public function test_internal_action_rejects_non_admin(): void
    {
        // Invocación interna con actor no autorizado.
        $customer = $this->customer();
        $user = User::factory()->create();
        $user->givePermissionTo('customer-accounts.adjust');
        $this->expectException(AuthorizationException::class);
        app(AdjustCustomerAccountAction::class)->execute($customer, $user,
            ['direction' => 'increase', 'amount' => '1', 'reason' => 'Intento interno', 'expected_version' => 0], (string) Str::uuid());
    }

    /** Una falla de auditoría revierte saldo, movimiento y operación conjuntamente. */
    public function test_audit_failure_rolls_back_all_financial_state(): void
    {
        // Fallo controlado en la dependencia de auditoría.
        $customer = $this->customer();
        $audit = Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit unavailable'));
        $this->app->instance(AuditRecorder::class, $audit);
        $this->withoutExceptionHandling();
        try {
            $this->command($customer, 'pagos', ['amount' => '1', 'payment_method' => 'cash']);
            $this->fail('La auditoría debía fallar.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('customer_account_movements', 0);
        $this->assertDatabaseCount('customer_operations', 0);
        $this->assertDatabaseHas('customer_accounts', ['customer_id' => $customer->id, 'balance' => '0.000', 'version' => 0]);
    }

    /** El resumen de período no cambia al filtrar o paginar las líneas visibles. */
    public function test_statement_totals_include_all_movements_independent_of_type_filter(): void
    {
        // Cargo inicial mediante ajuste y dos cobros independientes.
        $customer = $this->customer();
        $this->command($customer, 'ajustes', ['direction' => 'increase', 'amount' => '100', 'reason' => 'Apertura', 'expected_version' => 0])->assertOk();
        $this->command($customer, 'pagos', ['amount' => '20', 'payment_method' => 'cash'])->assertOk();
        $this->command($customer, 'pagos', ['amount' => '10', 'payment_method' => 'cash'])->assertOk();
        $this->getJson('/api/v1/clientes/'.$customer->public_id.'/movimientos?type=payment&per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('statement.closing_balance', '70.000');
    }

    /** La sesión PostgreSQL en Montevideo no desplaza el instante ni el día contable del movimiento. */
    public function test_timestamps_preserve_utc_instant_and_local_statement_day(): void
    {
        // La hora UTC pertenece todavía al día anterior en Uruguay.
        $this->travelTo(CarbonImmutable::parse('2026-10-29T02:30:00+00:00'));
        $customer = $this->customer();
        $this->command($customer, 'pagos', ['amount' => '1.125', 'payment_method' => 'cash'])->assertOk()
            ->assertJsonPath('data.0.created_at', '2026-10-29T02:30:00+00:00')
            ->assertJsonPath('data.0.occurred_at', '2026-10-29T02:30:00+00:00');
        // El filtro local incluye la operación el 28 y no vuelve a mostrarla el 29.
        $this->getJson('/api/v1/clientes/'.$customer->public_id.'/movimientos?from=2026-10-28&to=2026-10-28')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('statement.closing_balance', '-1.125');
        $this->getJson('/api/v1/clientes/'.$customer->public_id.'/movimientos?from=2026-10-29&to=2026-10-29')
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('statement.opening_balance', '-1.125');
    }
}
