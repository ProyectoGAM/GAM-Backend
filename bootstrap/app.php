<?php

use App\Http\Middleware\AssignTraceContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AssignTraceContext::class);
        $middleware->statefulApi();

        $middleware->alias([
            'ability' => CheckForAnyAbility::class,
            'abilities' => CheckAbilities::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('administracion') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $exception, Request $request): ?Response {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => 'Los datos proporcionados no son válidos.',
                'errors' => $exception->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request): ?Response {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => 'No estás autenticado.',
            ], Response::HTTP_UNAUTHORIZED);
        });

        $exceptions->render(function (AccessDeniedHttpException $exception, Request $request): ?Response {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => 'No tienes autorización para realizar esta acción.',
            ], Response::HTTP_FORBIDDEN);
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request): ?Response {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => 'El recurso solicitado no existe.',
            ], Response::HTTP_NOT_FOUND);
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request): ?Response {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $message = match ($exception->getStatusCode()) {
                Response::HTTP_METHOD_NOT_ALLOWED => 'El método HTTP no está permitido.',
                Response::HTTP_TOO_MANY_REQUESTS => 'Demasiadas solicitudes. Inténtalo nuevamente más tarde.',
                419 => 'La sesión expiró.',
                default => null,
            };

            if ($message === null) {
                return null;
            }

            return response()->json([
                'message' => $message,
            ], $exception->getStatusCode());
        });
    })->create();
