<?php

namespace App\Policies\ReportingAndAnalytics;

use App\Exceptions\ReportingAndAnalytics\ReportSourceNotFoundException;
use App\Models\ReportingAndAnalytics\ReportExport;
use App\Models\User;
use App\Services\ReportingAndAnalytics\ReportSourceRegistry;

final readonly class ReportExportPolicy
{
    public function __construct(private ReportSourceRegistry $registry) {}

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'reports.view');
    }

    public function view(User $user, ReportExport $export): bool
    {
        return $this->owns($user, $export) && $this->allowed($user, 'reports.view');
    }

    public function download(User $user, ReportExport $export): bool
    {
        return $this->view($user, $export) && $this->canReadSource($user, $export);
    }

    public function share(User $user, ReportExport $export): bool
    {
        return $this->owns($user, $export) && $this->allowed($user, 'reports.share') && $this->canReadSource($user, $export);
    }

    private function canReadSource(User $user, ReportExport $export): bool
    {
        try {
            return $this->registry->canRead($user, $this->registry->get($export->source_key));
        } catch (ReportSourceNotFoundException) {
            return false;
        }
    }

    private function owns(User $user, ReportExport $export): bool
    {
        return (int) $export->user_id === (int) $user->getKey();
    }

    private function allowed(User $user, string $permission): bool
    {
        return $user->hasRole('admin') || $user->checkPermissionTo($permission);
    }
}
