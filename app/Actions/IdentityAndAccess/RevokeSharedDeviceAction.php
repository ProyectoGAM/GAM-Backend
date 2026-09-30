<?php

namespace App\Actions\IdentityAndAccess;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\SharedDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RevokeSharedDeviceAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(SharedDevice $device, User $actor, string $reason = 'device_revoked'): void
    {
        DB::transaction(function () use ($device, $actor, $reason): void {
            $device->forceFill(['revoked_at' => now()])->save();
            $device->sessions()->whereNull('revoked_at')->update([
                'revoked_at' => now(),
                'revoked_reason' => $reason,
            ]);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $device,
                actor: $actor,
                logName: 'identity',
                event: 'shared_device_revoked',
                description: 'Dispositivo compartido revocado',
                properties: ['reason' => $reason],
                source: 'api',
            ));
        });
    }
}
