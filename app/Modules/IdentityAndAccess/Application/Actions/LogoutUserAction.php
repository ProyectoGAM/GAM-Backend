<?php

namespace App\Modules\IdentityAndAccess\Application\Actions;

use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

final class LogoutUserAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $this->revokePersonalAccessToken($user->currentAccessToken());

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $user,
                actor: $user,
                logName: 'identity',
                event: 'user_logged_out',
                description: 'Usuario cerró la sesión',
                properties: [
                    'subject_snapshot' => [
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                ],
                source: 'api',
            ));
        });
    }

    private function revokePersonalAccessToken(mixed $accessToken): void
    {
        if ($accessToken instanceof PersonalAccessToken) {
            $accessToken->delete();
        }
    }
}
