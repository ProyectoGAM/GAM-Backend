<?php

namespace App\Models;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\IdentityAndAccess\AuthSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int|null $production_unit_id
 * @property Carbon|null $credential_expires_at
 * @property Carbon|null $enrolled_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_seen_at
 */
final class SharedDevice extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['credential_hash'];

    protected static function booted(): void
    {
        self::creating(function (self $device): void {
            $device->id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'production_unit_id' => 'integer',
            'credential_expires_at' => 'datetime',
            'enrolled_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'session_generation' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function enrolledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by');
    }

    /** @return HasMany<AuthSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->credential_expires_at?->isFuture() === true
            && $this->production_unit_id !== null
            && ProductionUnit::query()->whereKey($this->production_unit_id)->where('status', 'active')->exists();
    }
}
