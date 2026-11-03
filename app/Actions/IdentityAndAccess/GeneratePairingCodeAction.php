<?php

namespace App\Actions\IdentityAndAccess;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\SharedDevicePairingCode;
use App\Models\User;
use App\Services\IdentityAndAccess\AccessControl;
use Illuminate\Support\Facades\DB;

final class GeneratePairingCodeAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @return array{code: string, expires_at: string} */
    public function execute(User $actor, string $name, int $productionUnitId): array
    {
        return DB::transaction(function () use ($actor, $name, $productionUnitId): array {
            $actor = app(AccessControl::class)->lockActor($actor);
            abort_unless($actor->hasRole('admin') && ! $actor->usesDevicePin() && $actor->can('can_manage_shared_devices'), 403);
            ProductionUnit::query()->whereKey($productionUnitId)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $code = $this->code();
            $expiresAt = now()->addMinutes((int) config('identity.pairing_minutes', 10));

            $pairing = SharedDevicePairingCode::query()->create([
                'code_hash' => hash('sha256', $code),
                'name' => $name,
                'production_unit_id' => $productionUnitId,
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
                upId: $productionUnitId,
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
