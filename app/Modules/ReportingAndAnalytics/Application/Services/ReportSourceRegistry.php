<?php

namespace App\Modules\ReportingAndAnalytics\Application\Services;

use App\Models\User;
use App\Modules\ReportingAndAnalytics\Domain\Contracts\ReportSource;
use App\Modules\ReportingAndAnalytics\Domain\Exceptions\ReportSourceNotFoundException;
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
                && $this->allows($user, $source->definition()->permission),
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
        return $this->allows($user, 'reports.view') && $this->allows($user, $source->definition()->permission);
    }

    public function assertCanExport(User $user, ReportSource $source): void
    {
        if (! $this->canExport($user, $source)) {
            throw new AuthorizationException('No tienes autorización para exportar esta fuente de reporte.');
        }
    }

    public function canExport(User $user, ReportSource $source): bool
    {
        return $this->allows($user, 'reports.export') && $this->allows($user, $source->definition()->permission);
    }

    private function allows(User $user, string $permission): bool
    {
        return $user->hasRole('admin') || $user->checkPermissionTo($permission);
    }
}
