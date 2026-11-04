<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int|null $production_unit_id
 * @property Carbon|null $expires_at
 * @property Carbon|null $consumed_at
 */
final class SharedDevicePairingCode extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['code_hash'];

    protected static function booted(): void
    {
        self::creating(function (self $code): void {
            $code->id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'production_unit_id' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<SharedDevice, $this> */
    public function sharedDevice(): BelongsTo
    {
        return $this->belongsTo(SharedDevice::class);
    }
}
