<?php

namespace App\Services\IdentityAndAccess;

use App\Actions\IdentityAndAccess\AuthenticateSharedPinAction;
use App\Exceptions\IdentityAndAccess\IdentityException;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

final class OperationPinVerifier
{
    public function __construct(private PinHasher $hasher) {}

    public function verify(User $user, string $pin): void
    {
        if ($user->usesDevicePin()) {
            app(AuthenticateSharedPinAction::class)->verifyPin($user, $pin);

            return;
        }
        $user->refresh();
        if ($user->operation_pin_hash === null) {
            throw new IdentityException(422, 'PIN_NOT_CONFIGURED', 'Debes configurar tu PIN de confirmación antes de realizar esta tarea.');
        }
        $key = 'operation-pin|'.$user->id;
        $this->ensureAllowed($key);
        if (! $this->hasher->check($pin, $user->operation_pin_hash)) {
            RateLimiter::hit($key, 900);
            RateLimiter::hit($key.'|daily', 86400);
            $this->ensureAllowed($key);
            throw new IdentityException(401, 'INVALID_PIN', 'El PIN no es válido.');
        }
        RateLimiter::clear($key);
    }

    private function ensureAllowed(string $key): void
    {
        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($key.'|daily', 20)) {
            throw new IdentityException(429, 'RATE_LIMITED', 'Demasiados intentos. Inténtalo nuevamente más tarde.');
        }
    }
}
