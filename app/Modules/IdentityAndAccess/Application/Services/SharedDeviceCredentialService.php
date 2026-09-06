<?php

namespace App\Modules\IdentityAndAccess\Application\Services;

use App\Models\SharedDevice;
use App\Modules\IdentityAndAccess\Http\Exceptions\IdentityException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class SharedDeviceCredentialService
{
    public function resolve(Request $request, bool $allowCookie = false, bool $allowHeader = true): SharedDevice
    {
        $credential = $allowHeader ? $request->header('X-Shared-Device-Token') : null;

        if ($allowCookie) {
            $credential ??= $request->cookie('gam_shared_device');
            $credential = is_string($credential) ? rawurldecode($credential) : $credential;
        }

        if (! is_string($credential) || ! str_contains($credential, '|')) {
            throw $this->unauthorized();
        }

        [$id, $secret] = explode('|', $credential, 2);
        $device = SharedDevice::query()->find($id);

        if (! $device instanceof SharedDevice
            || ! hash_equals((string) $device->credential_hash, hash('sha256', $secret))
            || ! $device->isUsable()) {
            throw $this->unauthorized();
        }

        $device->forceFill(['last_seen_at' => now()])->save();

        return $device;
    }

    /** @return array{token: string, hash: string} */
    public function issue(): array
    {
        $secret = Str::random(64);

        return [
            'token' => $secret,
            'hash' => hash('sha256', $secret),
        ];
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
