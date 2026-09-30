<?php

namespace App\Services\IdentityAndAccess;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

final class PinHasher
{
    public function hash(string $pin): string
    {
        return $this->driver()->make($this->peppered($pin));
    }

    public function check(string $pin, string $hash): bool
    {
        return $this->driver()->check($this->peppered($pin), $hash);
    }

    public function pepperVersion(): string
    {
        return (string) config('identity.pin.pepper_version');
    }

    private function peppered(string $pin): string
    {
        $pepper = config('identity.pin.pepper');

        if (! is_string($pepper) || $pepper === '') {
            throw new RuntimeException('IDENTITY_PIN_PEPPER debe configurarse antes de usar PIN.');
        }

        return hash_hmac('sha256', "gam-pin-v1|{$pin}", $pepper);
    }

    private function driver(): Hasher
    {
        return Hash::driver((string) config('identity.pin.hash_driver', 'argon2id'));
    }
}
