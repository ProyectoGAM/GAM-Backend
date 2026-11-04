<?php

namespace App\Services\ReportingAndAnalytics;

use App\Exceptions\ReportingAndAnalytics\ReportSourceNotFoundException;
use App\Interfaces\ReportingAndAnalytics\ReportSource;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class ReportSourceRegistry
{
    /**
     * @param  list<ReportSource>  $sources
     */
    public function __construct(private array $sources) {}

    /** @return list<ReportSource> */
    public function all(): array
    {
        return $this->sources;
    }

    /**
     * @return list<ReportSource>
     */
    public function authorizedFor(User $user, string $permission = 'can_view_reports'): array
    {
        return array_values(array_filter(
            $this->sources,
            fn (ReportSource $source): bool => $this->allows($user, $permission)
                && $this->allowsSource($user, $source),
        ));
    }

    public function get(string $key): ReportSource
    {
        foreach ($this->sources as $source) {
            if ($source->definition()->key === $key) {
                return $source;
            }
        }

        throw new ReportSourceNotFoundException($key);
    }

    public function assertCanRead(User $user, ReportSource $source): void
    {
        if (! $this->canRead($user, $source)) {
            throw new AuthorizationException('No tienes autorización para consultar esta fuente de reporte.');
        }
    }

    public function canRead(User $user, ReportSource $source): bool
    {
        return $this->allows($user, 'can_view_reports') && $this->allowsSource($user, $source);
    }

    public function assertCanExport(User $user, ReportSource $source): void
    {
        if (! $this->canExport($user, $source)) {
            throw new AuthorizationException('No tienes autorización para exportar esta fuente de reporte.');
        }
    }

    public function canExport(User $user, ReportSource $source): bool
    {
        return $this->allows($user, 'can_export_reports') && $this->allowsSource($user, $source);
    }

    public function canShare(User $user, ReportSource $source): bool
    {
        return ! $user->usesDevicePin() && $this->canRead($user, $source) && $this->allows($user, 'can_share_reports');
    }

    private function allowsSource(User $user, ReportSource $source): bool
    {
        if ($user->usesDevicePin() && ! array_key_exists('production_unit_id', $source->definition()->columns)) {
            return false;
        }
        foreach ([$source->definition()->permission, ...$source->definition()->additionalPermissions] as $permission) {
            if (! $this->allows($user, $permission)) {
                return false;
            }
        }

        return true;
    }

    private function allows(User $user, string $permission): bool
    {
        return $user->hasRole('admin') || $user->checkPermissionTo($permission);
    }
}
