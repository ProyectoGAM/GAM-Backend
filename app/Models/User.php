<?php

namespace App\Models;

use App\Enums\IdentityAndAccess\AccessMethod;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\IdentityAndAccess\AuthSession;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $password
 * @property AccessMethod $access_method
 * @property int|null $production_unit_id
 * @property string|null $operation_pin_hash
 * @property string|null $pin_hash
 * @property bool $pin_enabled
 * @property Carbon|null $pin_changed_at
 * @property Carbon|null $pin_locked_until
 * @property Carbon|null $pin_failed_window_started_at
 * @property Carbon|null $pin_daily_window_started_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'pin_hash', 'pin_pepper_version', 'operation_pin_hash'])]
class User extends Authenticatable
{
    protected $attributes = ['access_method' => 'email_password'];

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
            'access_method' => AccessMethod::class,
            'production_unit_id' => 'integer',
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

    /** @return HasMany<AuthSession, $this> */
    public function authSessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }

    /** @return BelongsTo<ProductionUnit, $this> */
    public function productionUnit(): BelongsTo
    {
        return $this->belongsTo(ProductionUnit::class);
    }

    public function usesDevicePin(): bool
    {
        return $this->access_method === AccessMethod::DevicePin;
    }

    public function hasAccessRole(): bool
    {
        return $this->roles()->where('name', '!=', 'unassigned')->exists();
    }

    /** Los permisos efectivos proceden exclusivamente del único rol y se consultan sin caché compartida. */
    public function hasPermissionTo(mixed $permission, ?string $guardName = null): bool
    {
        if ($this->trashed()) {
            return false;
        }
        $permission = $this->filterPermission($permission, $guardName);

        return $this->roles()->where('guard_name', $guardName ?? 'web')
            ->where('name', '!=', 'unassigned')
            ->whereHas('permissions', fn ($query) => $query->whereKey($permission->getKey()))->exists();
    }

    public function getAllPermissions(): Collection
    {
        return $this->roles()->where('name', '!=', 'unassigned')->with('permissions')->get()->pluck('permissions')->flatten()
            ->unique('id')->sortBy('name')->values()->pipe(fn ($permissions): Collection => new Collection($permissions->all()));
    }

    public function givePermissionTo(mixed ...$permissions): static
    {
        throw ValidationException::withMessages(['permisos' => 'Los permisos se configuran en el rol, no en el usuario.']);
    }

    public function syncPermissions(mixed ...$permissions): static
    {
        throw ValidationException::withMessages(['permisos' => 'Los permisos se configuran en el rol, no en el usuario.']);
    }
}
