<?php

namespace App\Actions\IdentityAndAccess;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Exceptions\IdentityAndAccess\IdentityException;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\SharedDevice;
use App\Models\SharedDevicePairingCode;
use App\Models\User;
use App\Services\IdentityAndAccess\SharedDeviceCredentialService;
use Illuminate\Support\Facades\DB;

final class RedeemPairingCodeAction
{
    public function __construct(
        private SharedDeviceCredentialService $credentials,
        private AuditRecorder $auditRecorder,
    ) {}

    /** @return array{device: SharedDevice, token: string} */
    public function execute(string $code, string $name): array
    {
        return DB::transaction(function () use ($code, $name): array {
            $pairing = SharedDevicePairingCode::query()
                ->where('code_hash', hash('sha256', $code))
                ->lockForUpdate()
                ->first();

            if (! $pairing instanceof SharedDevicePairingCode
                || $pairing->consumed_at !== null
                || $pairing->expires_at?->isPast()
                || ! $pairing->creator()->whereNull('deleted_at')->whereHas('roles', fn ($query) => $query->where('name', 'admin'))->exists()) {
                throw new IdentityException(422, 'INVALID_PAIRING_CODE', 'El código de vinculación no es válido.');
            }

            $issued = $this->credentials->issue();
            $device = SharedDevice::query()->create([
                'name' => $name !== '' ? $name : $pairing->name,
                'credential_hash' => $issued['hash'],
                'credential_expires_at' => now()->addDays((int) config('identity.shared_device_days', 365)),
                'enrolled_by' => $pairing->created_by,
                'enrolled_at' => now(),
            ]);

            $pairing->forceFill([
                'consumed_at' => now(),
                'shared_device_id' => $device->getKey(),
            ])->save();

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $device,
                actor: User::query()->find($pairing->created_by),
                logName: 'identity',
                event: 'shared_device_paired',
                description: 'Dispositivo compartido vinculado',
                properties: ['device_name' => $device->name],
                source: 'api',
            ));

            return ['device' => $device, 'token' => $device->getKey().'|'.$issued['token']];
        });
    }
}
