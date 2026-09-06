<?php

namespace Tests\Feature\IdentityAndAccess;

use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\IdentityAndAccess\AuthSession;
use App\Models\SharedDevice;
use App\Models\User;
use App\Modules\IdentityAndAccess\Application\Services\PinHasher;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SharedDeviceAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_native_pin_login_preserves_leading_zero_and_emits_shared_context(): void
    {
        [$employee, $device, $credential] = $this->sharedFixture();

        $response = $this->withHeader('X-Shared-Device-Token', $credential)
            ->postJson('/api/v1/dispositivo-compartido/inicio-sesion-pin', [
                'usuario_id' => $employee->getKey(),
                'pin' => '0007',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.id', $employee->getKey())
            ->assertJsonPath('session.kind', 'shared_user')
            ->assertJsonPath('session.auth_method', 'pin');

        $this->assertNotEmpty($response->json('access_token'));
        $this->assertDatabaseHas('auth_sessions', [
            'user_id' => $employee->getKey(),
            'shared_device_id' => $device->getKey(),
            'kind' => 'shared_user',
            'transport' => 'bearer',
        ]);
    }

    public function test_five_wrong_pin_attempts_are_rate_limited(): void
    {
        [$employee, , $credential] = $this->sharedFixture();

        for ($attempt = 1; $attempt < 5; $attempt++) {
            $this->withHeader('X-Shared-Device-Token', $credential)
                ->postJson('/api/v1/dispositivo-compartido/inicio-sesion-pin', [
                    'usuario_id' => $employee->getKey(),
                    'pin' => '9999',
                ])
                ->assertUnauthorized()
                ->assertJsonPath('code', 'INVALID_PIN');
        }

        $this->withHeader('X-Shared-Device-Token', $credential)
            ->postJson('/api/v1/dispositivo-compartido/inicio-sesion-pin', [
                'usuario_id' => $employee->getKey(),
                'pin' => '9999',
            ])
            ->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMITED');
    }

    public function test_web_pin_login_uses_cookie_session_without_returning_a_bearer_token(): void
    {
        [$employee, $device, $credential] = $this->sharedFixture();

        $this->withCookie('gam_shared_device', $credential)
            ->withCredentials()
            ->getJson('/api/v1/dispositivo-compartido/web')
            ->assertOk()
            ->assertJsonPath('data.id', $device->getKey());

        $response = $this->withCookie('gam_shared_device', $credential)
            ->withCredentials()
            ->postJson('/api/v1/dispositivo-compartido/web/inicio-sesion-pin', [
                'usuario_id' => $employee->getKey(),
                'pin' => '0007',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.id', $employee->getKey())
            ->assertJsonMissingPath('access_token');

        $this->assertDatabaseHas('auth_sessions', [
            'user_id' => $employee->getKey(),
            'shared_device_id' => $device->getKey(),
            'kind' => 'shared_user',
            'transport' => 'cookie',
        ]);
    }

    public function test_local_device_revoke_requires_an_administrator_password(): void
    {
        [$employee, $device, $credential, $admin] = $this->sharedFixture();

        $this->withHeader('X-Shared-Device-Token', $credential)
            ->deleteJson('/api/v1/dispositivo-compartido/vinculacion', [
                'correo_electronico' => $admin->email,
                'password' => 'password',
            ])
            ->assertOk();

        $this->assertDatabaseHas('shared_devices', [
            'id' => $device->getKey(),
        ]);
        $this->assertNotNull($device->fresh()->revoked_at);
        $this->assertNull($employee->fresh()->authSessions()->first());
        $audit = AuditEntry::query()->where('event', 'shared_device_revoked')->latest('id')->firstOrFail();
        $this->assertNull($audit->subject_id);
        $this->assertSame($device->getKey(), $audit->properties->get('subject_key'));
    }

    /** @return array{0: User, 1: SharedDevice, 2: string, 3: User} */
    private function sharedFixture(): array
    {
        $this->seed(IdentityPermissionSeeder::class);

        $employee = User::factory()->create();
        $employee->assignRole('employee');
        $employee->forceFill([
            'pin_hash' => app(PinHasher::class)->hash('0007'),
            'pin_enabled' => true,
            'pin_pepper_version' => config('identity.pin.pepper_version'),
            'pin_daily_window_started_at' => now(),
        ])->save();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $secret = Str::random(64);
        $device = SharedDevice::query()->create([
            'name' => 'Test tablet',
            'credential_hash' => hash('sha256', $secret),
            'credential_expires_at' => now()->addYear(),
            'enrolled_by' => $admin->getKey(),
            'enrolled_at' => now(),
        ]);

        $this->assertSame(0, AuthSession::query()->count());

        return [$employee, $device, $device->getKey().'|'.$secret, $admin];
    }
}
