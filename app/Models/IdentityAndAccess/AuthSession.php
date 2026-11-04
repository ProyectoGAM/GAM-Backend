<?php

namespace App\Models\IdentityAndAccess;

use App\Models\SharedDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int|null $production_unit_id
 * @property Carbon|null $issued_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_user_activity_at
 * @property Carbon|null $password_confirmed_at
 * @property Carbon|null $revoked_at
 */
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
            'production_unit_id' => 'integer',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_user_activity_at' => 'datetime',
            'password_confirmed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'device_generation' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<SharedDevice, $this> */
    public function sharedDevice(): BelongsTo
    {
        return $this->belongsTo(SharedDevice::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at?->isFuture() === true;
    }
}
