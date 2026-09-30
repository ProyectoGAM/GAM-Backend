<?php

namespace App\Http\Middleware;

use App\Exceptions\IdentityAndAccess\IdentityException;
use App\Services\IdentityAndAccess\AuthSessionService;
use App\Services\IdentityAndAccess\SharedDeviceCredentialService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveSharedDevice
{
    public function __construct(
        private SharedDeviceCredentialService $credentials,
        private AuthSessionService $sessions,
    ) {}

    public function handle(Request $request, Closure $next, string $transport = 'any'): Response
    {
        if ($transport === 'any') {
            $session = $this->sessions->current($request);
            if ($session === null || $session->kind !== 'shared_user') {
                return $next($request);
            }

            $transport = $session->transport === 'cookie' ? 'web' : 'native';
        }

        $hasHeaderCredential = is_string($request->header('X-Shared-Device-Token'))
            && trim((string) $request->header('X-Shared-Device-Token')) !== '';
        $hasCookieCredential = is_string($request->cookie('gam_shared_device'))
            && trim((string) $request->cookie('gam_shared_device')) !== '';
        if (($transport === 'web' && $hasHeaderCredential) || ($transport === 'native' && $hasCookieCredential)) {
            throw $this->unauthorized();
        }

        $request->attributes->set('shared_transport', $transport);
        $request->attributes->set('shared_device', $this->credentials->resolve(
            $request,
            allowCookie: $transport === 'web',
            allowHeader: $transport === 'native',
        ));

        return $next($request);
    }

    private function unauthorized(): IdentityException
    {
        return new IdentityException(
            401,
            'SHARED_DEVICE_UNAUTHORIZED',
            'El dispositivo compartido no está autorizado.',
        );
    }
}
