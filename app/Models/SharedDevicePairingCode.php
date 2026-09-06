<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sharedDevice(): BelongsTo
    {
        return $this->belongsTo(SharedDevice::class);
    }
}
