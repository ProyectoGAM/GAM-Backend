<?php

namespace App\Actions\IdentityAndAccess;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\SharedDevicePairingCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class GeneratePairingCodeAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @return array{code: string, expires_at: string} */
    public function execute(User $actor, string $name): array
    {
        return DB::transaction(function () use ($actor, $name): array {
            $code = $this->code();
            $expiresAt = now()->addMinutes((int) config('identity.pairing_minutes', 10));

            $pairing = SharedDevicePairingCode::query()->create([
                'code_hash' => hash('sha256', $code),
                'name' => $name,
                'created_by' => $actor->getKey(),
                'expires_at' => $expiresAt,
            ]);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $pairing,
                actor: $actor,
                logName: 'identity',
                event: 'shared_device_pairing_code_created',
                description: 'Código de vinculación de dispositivo creado',
                properties: ['expires_at' => $expiresAt->toIso8601String()],
                source: 'api',
            ));

            return ['code' => $code, 'expires_at' => $expiresAt->toIso8601String()];
        });
    }

    private function code(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($index = 0; $index < 10; $index++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }
}
