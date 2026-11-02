<?php

namespace App\Models\IdentityAndAccess;

use App\Models\User;
use Database\Factories\IdentityAndAccess\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\Permission\Models\Role as BaseRole;

/**
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property string|null $label
 * @property string|null $description
 * @property int $version
 */
class Role extends BaseRole
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    protected $fillable = ['name', 'guard_name', 'label', 'description'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    /** @return MorphToMany<User, $this> */
    public function users(): MorphToMany
    {
        return $this->morphedByMany(User::class, 'model', config('permission.table_names.model_has_roles'), 'role_id', 'model_id');
    }

    public function isProtected(): bool
    {
        return in_array($this->name, ['admin', 'employee', 'delivery'], true);
    }
}
