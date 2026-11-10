<?php

namespace App\Actions\IdentityAndAccess;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Exceptions\IdentityAndAccess\IdentityException;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\User;
use App\Services\IdentityAndAccess\AccessControl;
use App\Services\IdentityAndAccess\PinHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class ChangeOwnCredentialAction
{
    public function __construct(private AccessControl $access, private PinHasher $hasher, private AuditRecorder $audit) {}

    /** @param array<string, string> $data */
    public function execute(User $actor, string $credential, array $data): void
    {
        $verifiedHash = null;
        if ($credential === 'access_pin') {
            app(AuthenticateSharedPinAction::class)->verifyPin($actor, $data['current_pin']);
            $verifiedHash = $actor->pin_hash;
        }
        DB::transaction(function () use ($actor, $credential, $data, $verifiedHash): void {
            $actor = $this->access->lockActor($actor);
            $user = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->access->assertCurrentSession($user);
            if (! $user->hasAccessRole() || ($credential === 'access_pin') !== $user->usesDevicePin()) {
                throw new IdentityException(403, 'ACCESS_METHOD_CONFLICT', 'La credencial no corresponde a tu modalidad de acceso.');
            }
            if ($credential === 'access_pin') {
                if (! $user->pin_enabled || ! hash_equals((string) $verifiedHash, (string) $user->pin_hash)) {
                    throw new IdentityException(401, 'INVALID_PIN', 'El PIN no es válido.');
                }
                $user->forceFill(['pin_hash' => $this->hasher->hash($data['pin']), 'pin_changed_at' => now(), 'pin_pepper_version' => $this->hasher->pepperVersion()])->save();
            } else {
                if (! is_string($user->password) || ! Hash::check($data['current_password'], $user->password)) {
                    throw new IdentityException(401, 'INVALID_CREDENTIALS', 'Las credenciales proporcionadas no son correctas.');
                }
                $user->forceFill($credential === 'password'
                    ? ['password' => $data['password']]
                    : ['operation_pin_hash' => $this->hasher->hash($data['pin'])])->save();
            }
            $this->access->revokeAll($user, 'credential_changed');
            $this->audit->record(AuditEntryData::forSubject(
                subject: $user, actor: $user, logName: 'identity', event: 'user_credential_changed',
                description: 'Credencial propia actualizada y sesiones revocadas',
                properties: ['credential_type' => $credential, 'result' => 'success'], upId: $user->production_unit_id, source: 'api',
            ));
        });
    }
}
