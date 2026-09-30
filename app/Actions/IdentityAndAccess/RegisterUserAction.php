<?php

namespace App\Actions\IdentityAndAccess;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RegisterUserAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function execute(array $data, ?User $actor = null): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $user,
                actor: $actor,
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
