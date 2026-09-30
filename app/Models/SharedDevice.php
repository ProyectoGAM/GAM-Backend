<?php

namespace App\Models;

use App\Models\IdentityAndAccess\AuthSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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
            'credential_expires_at' => 'datetime',
            'enrolled_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'session_generation' => 'integer',
        ];
    }

    public function enrolledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->credential_expires_at?->isFuture() === true;
    }
}
