<?php

namespace App\Http\Middleware;

use App\Modules\IdentityAndAccess\Application\Services\SharedDeviceCredentialService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveSharedDevice
{
    public function __construct(private SharedDeviceCredentialService $credentials) {}

    public function handle(Request $request, Closure $next, string $transport = 'any'): Response
    {
        $request->attributes->set('shared_device', $this->credentials->resolve(
            $request,
            allowCookie: $transport !== 'native',
            allowHeader: $transport !== 'web',
        ));

        return $next($request);
    }
}
