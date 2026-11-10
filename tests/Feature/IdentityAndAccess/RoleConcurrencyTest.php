<?php

namespace Tests\Feature\IdentityAndAccess;

use App\Actions\IdentityAndAccess\AuthenticateSharedPinAction;
use App\Actions\IdentityAndAccess\SetUserPinAction;
use App\Actions\IdentityAndAccess\UpdateRolePermissionsAction;
use App\Actions\IdentityAndAccess\UpdateUserRoleAction;
use App\Exceptions\IdentityAndAccess\IdentityException;
use App\Models\IdentityAndAccess\Role;
use App\Models\SharedDevice;
use App\Models\User;
use App\Services\IdentityAndAccess\PinHasher;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RoleConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private ?string $originalAppKey = null;

    private bool $hadOriginalEnvAppKey = false;

    private ?string $originalEnvAppKey = null;

    private bool $hadOriginalServerAppKey = false;

    private ?string $originalServerAppKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalAppKey = getenv('APP_KEY') === false ? null : getenv('APP_KEY');
        $this->hadOriginalEnvAppKey = array_key_exists('APP_KEY', $_ENV);
        $this->originalEnvAppKey = $_ENV['APP_KEY'] ?? null;
        $this->hadOriginalServerAppKey = array_key_exists('APP_KEY', $_SERVER);
        $this->originalServerAppKey = $_SERVER['APP_KEY'] ?? null;
        $applicationKey = (string) config('app.key');
        putenv('APP_KEY='.$applicationKey);
        $_ENV['APP_KEY'] = $applicationKey;
        $_SERVER['APP_KEY'] = $applicationKey;
    }

    protected function tearDown(): void
    {
        if ($this->originalAppKey === null) {
            putenv('APP_KEY');
        } else {
            putenv('APP_KEY='.$this->originalAppKey);
        }
        if ($this->hadOriginalEnvAppKey) {
            $_ENV['APP_KEY'] = $this->originalEnvAppKey;
        } else {
            unset($_ENV['APP_KEY']);
        }
        if ($this->hadOriginalServerAppKey) {
            $_SERVER['APP_KEY'] = $this->originalServerAppKey;
        } else {
            unset($_SERVER['APP_KEY']);
        }

        parent::tearDown();
    }

    /** Los procesos secundarios necesitan datos confirmados, nunca la base de desarrollo. */
    public function runDatabaseMigrations(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'pgsql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_testing')) {
            $this->markTestSkipped('La concurrencia real requiere una base PostgreSQL aislada con sufijo _testing.');
        }
        $this->migrateIsolatedDatabase();
    }

    // Flujo: dos administradores no pueden retirarse simultáneamente y dejar la empresa sin Admin.
    public function test_concurrent_demotions_preserve_one_active_administrator(): void
    {
        // Preparación: confirma dos cuentas Admin para que ambos procesos puedan leerlas.
        $this->seed(IdentityPermissionSeeder::class);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $first->syncRoles(['admin']);
        $second->syncRoles(['admin']);
        $firstId = $first->id;
        $secondId = $second->id;
        $employeeId = Role::findByName('employee', 'web')->id;
        $demote = static function (int $id) use ($employeeId): int {
            try {
                $user = User::findOrFail($id);
                app(UpdateUserRoleAction::class)->execute($user, $user, $employeeId);

                return 200;
            } catch (IdentityException $exception) {
                return $exception->getStatusCode();
            }
        };

        // Mutación: compiten por retirar su propio rol bajo el bloqueo compartido.
        $results = Concurrency::driver('process')->run([
            static fn (): int => $demote($firstId), static fn (): int => $demote($secondId),
        ], timeout: 30);
        sort($results);
        $this->assertSame([200, 409], $results);
        $this->assertSame(1, User::query()->role('admin')->count());
        $this->assertSame(1, DB::table('activity_log')->where('event', 'user_role_updated')->count());
    }

    /** Un login concurrente con el cambio de PIN no deja una sesión con la credencial anterior. */
    public function test_pin_login_racing_with_reset_cannot_leave_an_old_credential_session(): void
    {
        // Prepara cuentas y dispositivo confirmados para los dos procesos.
        $this->seed(IdentityPermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->syncRoles('admin');
        $user = User::factory()->devicePin()->create();
        $user->syncRoles('employee');
        $device = SharedDevice::query()->create(['name' => 'Concurrencia', 'production_unit_id' => $user->production_unit_id,
            'credential_hash' => hash('sha256', 'race-device-test'), 'credential_expires_at' => now()->addYear(), 'enrolled_by' => $admin->id, 'enrolled_at' => now()]);
        $userId = $user->id;
        $adminId = $admin->id;
        $deviceId = $device->id;
        // Compite el acceso con PIN anterior contra el restablecimiento administrativo.
        $results = Concurrency::driver('process')->run([
            static function () use ($userId, $deviceId): int {
                try {
                    app(AuthenticateSharedPinAction::class)->execute(SharedDevice::findOrFail($deviceId), $userId, '0007');

                    return 200;
                } catch (IdentityException $exception) {
                    return $exception->getStatusCode();
                }
            },
            static function () use ($userId, $adminId): int {
                app(SetUserPinAction::class)->execute(User::findOrFail($adminId), User::findOrFail($userId), '0012');

                return 200;
            },
        ], timeout: 30);
        $this->assertContains($results[0], [200, 401]);
        $this->assertSame(200, $results[1]);
        $this->assertSame(0, $user->authSessions()->whereNull('revoked_at')->count());
        $this->assertTrue(app(PinHasher::class)->check('0012', $user->fresh()->pin_hash));
    }

    // Flujo: dos guardados con la misma versión no pueden pisar los permisos del mismo rol.
    public function test_concurrent_permission_edits_return_one_version_conflict(): void
    {
        // Preparación: crea un rol vacío y una cuenta Admin con datos confirmados.
        $this->seed(IdentityPermissionSeeder::class);
        $actor = User::factory()->create();
        $actor->syncRoles(['admin']);
        $actorId = $actor->id;
        $roleId = Role::query()->create(['name' => 'concurrent_operator', 'label' => 'Operador', 'guard_name' => 'web'])->id;
        $edit = static function (string $permission) use ($actorId, $roleId): int {
            try {
                app(UpdateRolePermissionsAction::class)->execute(User::findOrFail($actorId), Role::findOrFail($roleId), [$permission], 1);

                return 200;
            } catch (IdentityException $exception) {
                return $exception->getStatusCode();
            }
        };

        // Mutación: sólo un guardado puede consumir la versión inicial.
        $results = Concurrency::driver('process')->run([
            static fn (): int => $edit('can_view_flocks'), static fn (): int => $edit('can_view_inventory'),
        ], timeout: 30);
        sort($results);
        $this->assertSame([200, 409], $results);
        $this->assertSame(2, Role::findOrFail($roleId)->version);
        $this->assertSame(1, DB::table('activity_log')->where('event', 'role_permissions_updated')->count());
    }
}
