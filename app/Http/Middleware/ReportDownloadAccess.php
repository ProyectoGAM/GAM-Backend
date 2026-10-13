<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class ReportDownloadAccess
{
    public function __construct(private Authenticate $auth, private ResolveSharedDevice $device, private ValidateConditionalSharedSession $session) {}

    /** El enlace firmado permite visita anónima; la descarga privada exige el contexto completo. */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->boolean('share')) {
            if (! $request->hasValidSignature()) {
                throw new AccessDeniedHttpException('El enlace temporal no es válido o ya venció.');
            }

            return $next($request);
        }

        return $this->auth->handle($request, fn (Request $authenticated): Response => $this->device->handle($authenticated, fn (Request $deviceRequest): Response => $this->session->handle($deviceRequest, $next), 'any'), 'sanctum');
    }
}
