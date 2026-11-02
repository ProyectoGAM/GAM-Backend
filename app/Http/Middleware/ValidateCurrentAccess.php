<?php

namespace App\Http\Middleware;

use App\Exceptions\IdentityAndAccess\IdentityException;
use App\Services\IdentityAndAccess\AuthSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ValidateCurrentAccess
{
    public function __construct(private AuthSessionService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || $user->trashed()) {
            throw new IdentityException(401, 'SESSION_REVOKED', 'La sesión no es válida.');
        }
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $session = $this->sessions->current($request);
        if ($session !== null) {
            if (! $session->isUsable()) {
                $this->sessions->revoke($session, 'session_expired');
                $code = $session->kind === 'shared_user' ? 'SESSION_EXPIRED' : 'SESSION_REVOKED';
                throw new IdentityException(401, $code, 'La sesión ya no está vigente.');
            }
            $permission = $session->kind === 'shared_user' ? 'can_login_shared'
                : ($session->transport === 'cookie' ? 'can_login_web' : 'can_login_native');
            if (! $user->can($permission)
                || ($session->kind === 'shared_user' && $user->hasRole('admin'))) {
                $this->sessions->revoke($session, 'permissions_changed');
                throw new IdentityException(401, 'SESSION_REVOKED', 'La sesión ya no está autorizada.');
            }
        } elseif ($request->hasSession() && $request->session()->has('auth_session_id')) {
            throw new IdentityException(401, 'SESSION_REVOKED', 'La sesión ya no está autorizada.');
        }

        return $next($request);
    }
}
