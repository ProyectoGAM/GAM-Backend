<?php

namespace App\Http\Middleware;

use App\Models\IdentityAndAccess\AuthSession;
use App\Models\SharedDevice;
use App\Modules\IdentityAndAccess\Application\Services\AuthSessionService;
use App\Modules\IdentityAndAccess\Http\Exceptions\IdentityException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ValidateSharedSession
{
    public function __construct(private AuthSessionService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->attributes->get('shared_device');
        $session = $this->sessions->current($request);

        if (! $device instanceof SharedDevice || ! $session instanceof AuthSession) {
            throw $this->expired();
        }

        $expectedId = $request->header('X-GAM-Session');
        $lastActivity = $session->last_user_activity_at;

        if ($session->kind !== 'shared_user'
            || $session->shared_device_id !== $device->getKey()
            || $session->device_generation !== $device->session_generation
            || ! is_string($expectedId)
            || ! hash_equals((string) $session->getKey(), $expectedId)
            || ! $session->isUsable()
            || ($lastActivity !== null && $lastActivity->addSeconds((int) config('identity.shared_idle_seconds', 120))->isPast())) {
            $this->sessions->revoke($session, 'session_expired');
            throw $this->expired();
        }

        $request->attributes->set('auth_session', $session);
        $request->attributes->set('shared_user', $session->user);

        return $next($request);
    }

    private function expired(): IdentityException
    {
        return new IdentityException(401, 'SESSION_EXPIRED', 'La sesión del empleado expiró.');
    }
}
