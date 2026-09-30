<?php

namespace App\Models\IdentityAndAccess;

use App\Models\SharedDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class AuthSession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::creating(function (self $session): void {
            $session->id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_user_activity_at' => 'datetime',
            'password_confirmed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'device_generation' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sharedDevice(): BelongsTo
    {
        return $this->belongsTo(SharedDevice::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at?->isFuture() === true;
    }
}
