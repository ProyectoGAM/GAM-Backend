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
    public function authorizedFor(User $user, string $permission = 'reports.view'): array
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
        return $this->allows($user, 'reports.view') && $this->allowsSource($user, $source);
    }

    public function assertCanExport(User $user, ReportSource $source): void
    {
        if (! $this->canExport($user, $source)) {
            throw new AuthorizationException('No tienes autorización para exportar esta fuente de reporte.');
        }
    }

    public function canExport(User $user, ReportSource $source): bool
    {
        return $this->allows($user, 'reports.export') && $this->allowsSource($user, $source);
    }

    public function canShare(User $user, ReportSource $source): bool
    {
        return $this->canRead($user, $source) && $this->allows($user, 'reports.share');
    }

    private function allowsSource(User $user, ReportSource $source): bool
    {
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
