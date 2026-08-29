<?php

namespace App\Modules\IdentityAndAccess\Application\Actions;

use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use Illuminate\Support\Facades\DB;

final class RegisterUserAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function execute(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $user,
                actor: null,
                logName: 'identity',
                event: 'user_registered',
                description: 'Usuario registrado',
                properties: [
                    'subject_snapshot' => [
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                ],
                source: 'api',
            ));

            return $user;
        });
    }
}
