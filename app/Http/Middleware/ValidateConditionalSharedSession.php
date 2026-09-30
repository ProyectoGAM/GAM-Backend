<?php

namespace App\Http\Middleware;

use App\Exceptions\IdentityAndAccess\IdentityException;
use App\Models\IdentityAndAccess\AuthSession;
use App\Services\IdentityAndAccess\AuthSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ValidateConditionalSharedSession
{
    public function __construct(
        private AuthSessionService $sessions,
        private ValidateSharedSession $validator,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->sessions->current($request);
        if (! $session instanceof AuthSession || $session->kind !== 'shared_user') {
            return $next($request);
        }

        $expectedTransport = $session->transport === 'cookie' ? 'web' : 'native';
        if ($request->attributes->get('shared_transport') !== $expectedTransport) {
            throw new IdentityException(
                401,
                'SHARED_TRANSPORT_MISMATCH',
                'El transporte de la sesión compartida no coincide con sus credenciales.',
            );
        }

        return $this->validator->handle($request, $next);
    }
}
