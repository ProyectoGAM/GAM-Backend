<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\IdentityAndAccess\AuthSession;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $pin_hash
 * @property bool $pin_enabled
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'pin_hash', 'pin_pepper_version'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin_enabled' => 'boolean',
            'pin_changed_at' => 'datetime',
            'pin_failed_window_started_at' => 'datetime',
            'pin_locked_until' => 'datetime',
            'pin_daily_window_started_at' => 'datetime',
        ];
    }

    public function authSessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }
}
