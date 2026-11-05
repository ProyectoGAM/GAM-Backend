<?php

namespace Tests\Feature\IdentityAndAccess;

use App\Actions\IdentityAndAccess\SetUserPinAction;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Geography\Locality;
use App\Models\SharedDevice;
use App\Models\User;
use App\Services\IdentityAndAccess\PinHasher;
use Database\Seeders\IdentityAndAccess\RoleDemoSeeder;
use Database\Seeders\IdentityAndAccess\SharedUserDemoSeeder;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SharedUserDemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** El alta crea cuentas PIN auditadas y la recarga conserva los cambios de credencial. */
    public function test_seeds_four_pin_accounts_and_preserves_a_changed_pin_on_replay(): void
    {
        // Preparación: configura el pepper de prueba y las dependencias de la carga.
        [$admin, $ombu, $santaClara] = $this->createPrerequisites();
        $hasher = $this->app->make(PinHasher::class);

        // Carga y consulta: comprueba la modalidad, el rol, el hash y la UP de cada usuario.
        $this->app->call([new SharedUserDemoSeeder, 'run']);
        $users = User::query()->where('id', '!=', $admin->id)->with('roles')->get();
        $this->assertCount(4, $users);
        $this->assertSame(2, $users->where('production_unit_id', $ombu->id)->count());
        $this->assertSame(2, $users->where('production_unit_id', $santaClara->id)->count());
        foreach ($users as $user) {
            $this->assertTrue($user->usesDevicePin());
            $this->assertTrue($user->pin_enabled);
            $this->assertNull($user->email);
            $this->assertNull($user->password);
            $this->assertSame(['demo_poultry_house_operator'], $user->roles->pluck('name')->all());
            $this->assertTrue($hasher->check('1234', $user->pin_hash));
            $this->assertDatabaseHas('activity_log', [
                'event' => 'user_registered', 'source' => 'seeder', 'subject_id' => $user->id, 'up_id' => $user->production_unit_id,
            ]);
        }

        // Mutación: cambia un PIN por la vía habitual y repite la carga.
        $user = $users->firstOrFail();
        $this->app->make(SetUserPinAction::class)->execute($admin, $user, '0007');
        $this->app->call([new SharedUserDemoSeeder, 'run']);

        // Consulta: verifica que no se repitieron las altas ni se reescribió el PIN.
        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('activity_log', 9);
        $this->assertTrue($hasher->check('0007', $user->fresh()->pin_hash));
    }

    /** Dos cuentas con el mismo PIN sólo pueden iniciar sesión en su propia UP. */
    public function test_demo_pin_login_requires_a_device_from_the_assigned_unit(): void
    {
        // Preparación: siembra las cuentas y vincula un dispositivo de prueba a El Ombú.
        [$admin, $ombu, $santaClara] = $this->createPrerequisites();
        $this->app->call([new SharedUserDemoSeeder, 'run']);
        $secret = Str::random(64);
        $device = SharedDevice::query()->create([
            'name' => 'Tablet de prueba',
            'production_unit_id' => $ombu->id,
            'credential_hash' => hash('sha256', $secret),
            'credential_expires_at' => now()->addYear(),
            'enrolled_by' => $admin->id,
            'enrolled_at' => now(),
        ]);
        $localUser = User::query()->where('production_unit_id', $ombu->id)->firstOrFail();
        $otherUser = User::query()->where('production_unit_id', $santaClara->id)->firstOrFail();

        // Solicitudes: valida el PIN en la UP del dispositivo y rechaza al usuario de otra UP.
        $this->withHeader('X-Shared-Device-Token', $device->id.'|'.$secret)
            ->postJson('/api/v1/shared-device/login-pin', ['user_id' => $localUser->id, 'pin' => '1234'])
            ->assertOk()->assertJsonPath('user.id', $localUser->id);
        $this->postJson('/api/v1/shared-device/login-pin', ['user_id' => $otherUser->id, 'pin' => '1234'])
            ->assertUnauthorized();
    }

    /** El PIN conocido de la demo se reserva para local y testing. */
    public function test_does_not_seed_pin_accounts_in_production(): void
    {
        // Preparación: deja disponibles los roles y las UP e imita el reset demo de producción.
        $this->createPrerequisites();
        config(['app.demo_db_reset_enabled' => true]);
        $this->app->detectEnvironment(fn () => 'production');

        // Carga: invoca el seeder para comprobar su guarda.
        try {
            $this->app->call([new SharedUserDemoSeeder, 'run']);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseMissing('activity_log', ['event' => 'user_registered']);
    }

    /** @return array{User, ProductionUnit, ProductionUnit} */
    private function createPrerequisites(): array
    {
        config(['identity.pin.pepper' => 'shared-user-demo-test-pepper']);
        $this->seed(IdentityPermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);
        $this->app->call([new RoleDemoSeeder, 'run']);
        $ombu = ProductionUnit::factory()->for(Locality::factory()->state(['name' => 'Las Piedras']))
            ->create(['name' => 'Granja El Ombú']);
        $santaClara = ProductionUnit::factory()->for(Locality::factory()->state(['name' => 'Libertad']))
            ->create(['name' => 'Granja Santa Clara']);

        return [$admin, $ombu, $santaClara];
    }
}
