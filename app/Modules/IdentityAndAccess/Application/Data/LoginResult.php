<?php

namespace App\Modules\IdentityAndAccess\Application\Data;

use App\Models\User;
use LogicException;

final readonly class LoginResult
{
    private function __construct(private ?User $authenticatedUser) {}

    public static function success(User $user): self
    {
        return new self($user);
    }

    public static function invalidCredentials(): self
    {
        return new self(null);
    }

    public function isSuccessful(): bool
    {
        return $this->authenticatedUser instanceof User;
    }

    public function user(): User
    {
        if (! $this->authenticatedUser instanceof User) {
            throw new LogicException('El resultado de login no contiene un usuario autenticado.');
        }

        return $this->authenticatedUser;
    }
}
