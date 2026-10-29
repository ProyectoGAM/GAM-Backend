<?php

namespace Tests\Feature\CustomersAndAccounts;

use App\Actions\CustomersAndAccounts\AdjustCustomerAccountAction;
use App\Actions\CustomersAndAccounts\RecordCustomerPaymentAction;
use App\Exceptions\CustomersAndAccounts\CustomerAccountConflict;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

#[Group('customer-account-concurrency')]
final class CustomerAccountConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    /** @var array<string, string|false> Valores del proceso que deben restaurarse al cerrar la prueba. */
    private array $originalProcessEnvironment = [];

    /** @var array<string, string|null> Valores de entorno de PHP previos a la prueba. */
    private array $originalEnvEnvironment = [];

    /** @var array<string, string|null> Valores del servidor de PHP previos a la prueba. */
    private array $originalServerEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Preparación: comparte la clave de firma y la conexión aislada con los procesos secundarios.
        $processEnvironment = [
            'APP_KEY' => (string) config('app.key'),
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) config('database.connections.pgsql.host'),
            'DB_DATABASE' => DB::connection()->getDatabaseName(),
        ];
        foreach ($processEnvironment as $key => $value) {
            $this->originalProcessEnvironment[$key] = getenv($key);
            $this->originalEnvEnvironment[$key] = $_ENV[$key] ?? null;
            $this->originalServerEnvironment[$key] = $_SERVER[$key] ?? null;
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    protected function tearDown(): void
    {
        // Limpieza: evita propagar la configuración particular hacia otras pruebas.
        foreach ($this->originalProcessEnvironment as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
            if ($this->originalEnvEnvironment[$key] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $this->originalEnvEnvironment[$key];
            }
            if ($this->originalServerEnvironment[$key] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $this->originalServerEnvironment[$key];
            }
        }

        parent::tearDown();
    }

    /** Los procesos secundarios necesitan datos confirmados en una base exclusiva de pruebas. */
    public function runDatabaseMigrations(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'pgsql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_testing')) {
            $this->markTestSkipped('La concurrencia real requiere una base PostgreSQL aislada con sufijo _testing.');
        }
        $this->migrateIsolatedDatabase();
    }

    /** Dos respuestas perdidas del mismo cobro se resuelven con un único movimiento auditado. */
    public function test_concurrent_replays_post_one_payment(): void
    {
        // Preparación de datos confirmados, visibles para ambos procesos.
        $actor = User::factory()->create();
        $actor->assignRole(Role::findOrCreate('admin', 'web'));
        $actorId = $actor->id;
        $customerId = Customer::factory()->withAccount()->create(['created_by' => $actorId])->id;
        $key = (string) Str::uuid();
        $task = static function () use ($actorId, $customerId, $key): string {
            if (! app()->environment('testing') || ! str_ends_with(DB::connection()->getDatabaseName(), '_testing')) {
                throw new \RuntimeException('El proceso debe usar la base de pruebas exclusiva.');
            }

            return app(RecordCustomerPaymentAction::class)->execute(Customer::findOrFail($customerId), User::findOrFail($actorId),
                ['amount' => '12.625', 'payment_method' => 'cash'], $key)->operation_id;
        };
        // Dos procesos reales reintentan el mismo evento al mismo tiempo.
        $results = Concurrency::driver('process')->run([$task, $task], timeout: 30);
        $this->assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('customer_account_movements', 1);
        $this->assertDatabaseCount('customer_operations', 1);
        $this->assertDatabaseHas('customer_accounts', ['customer_id' => $customerId, 'balance' => '-12.625', 'version' => 1]);
        $this->assertDatabaseCount('activity_log', 1);
    }

    /** Dos administradores pueden cobrar simultáneamente sin perder una actualización de saldo. */
    public function test_two_actors_post_distinct_payments_to_same_account(): void
    {
        // Cada proceso tiene su actor y clave; el único recurso compartido es la cuenta.
        $first = User::factory()->create();
        $second = User::factory()->create();
        $role = Role::findOrCreate('admin', 'web');
        $first->assignRole($role);
        $second->assignRole($role);
        $firstId = $first->id;
        $secondId = $second->id;
        $customerId = Customer::factory()->withAccount()->create(['created_by' => $firstId])->id;
        $keys = [(string) Str::uuid(), (string) Str::uuid()];
        $firstTask = static function () use ($firstId, $customerId, $keys): string {
            return app(RecordCustomerPaymentAction::class)->execute(Customer::findOrFail($customerId), User::findOrFail($firstId),
                ['amount' => '10.000', 'payment_method' => 'cash'], $keys[0])->operation_id;
        };
        $secondTask = static function () use ($secondId, $customerId, $keys): string {
            return app(RecordCustomerPaymentAction::class)->execute(Customer::findOrFail($customerId), User::findOrFail($secondId),
                ['amount' => '20.125', 'payment_method' => 'transfer'], $keys[1])->operation_id;
        };
        // La concurrencia debe conservar ambos pagos y la secuencia completa.
        $results = Concurrency::driver('process')->run([$firstTask, $secondTask], timeout: 30);
        $this->assertNotSame($results[0], $results[1]);
        $this->assertDatabaseHas('customer_accounts', ['customer_id' => $customerId, 'balance' => '-30.125', 'version' => 2]);
        $this->assertSame([1, 2], DB::table('customer_account_movements')->orderBy('sequence')->pluck('sequence')->all());
        $this->assertDatabaseCount('activity_log', 2);
    }

    /** Un ajuste concurrente sólo se aplica si su vista previa aún coincide con la versión bloqueada. */
    public function test_payment_racing_adjustment_preserves_version_precondition(): void
    {
        // Actores diferentes evitan que el lock de identidad serialice artificialmente la cuenta.
        $first = User::factory()->create();
        $second = User::factory()->create();
        $role = Role::findOrCreate('admin', 'web');
        $first->assignRole($role);
        $second->assignRole($role);
        $firstId = $first->id;
        $secondId = $second->id;
        $customerId = Customer::factory()->withAccount()->create(['created_by' => $firstId])->id;
        $keys = [(string) Str::uuid(), (string) Str::uuid()];
        $payment = static function () use ($firstId, $customerId, $keys): string {
            return app(RecordCustomerPaymentAction::class)->execute(Customer::findOrFail($customerId), User::findOrFail($firstId),
                ['amount' => '10.000', 'payment_method' => 'cash'], $keys[0])->operation_id;
        };
        $adjustment = static function () use ($secondId, $customerId, $keys): string {
            try {
                app(AdjustCustomerAccountAction::class)->execute(Customer::findOrFail($customerId), User::findOrFail($secondId),
                    ['direction' => 'increase', 'amount' => '100.000', 'reason' => 'Revisión concurrente', 'expected_version' => 0], $keys[1]);

                return 'posted';
            } catch (CustomerAccountConflict) {
                return 'conflict';
            }
        };
        // Cualquiera de los dos órdenes seriales es correcto; perder dinero o aceptar versión obsoleta no lo es.
        $results = Concurrency::driver('process')->run([$payment, $adjustment], timeout: 30);
        $account = DB::table('customer_accounts')->where('customer_id', $customerId)->first();
        $this->assertContains([$results[1], $account->balance, $account->version], [
            ['posted', '90.000', 2], ['conflict', '-10.000', 1],
        ]);
        $this->assertSame($account->version, DB::table('customer_account_movements')->count());
        $this->assertSame($account->version, DB::table('activity_log')->count());
    }
}
