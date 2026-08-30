<?php

namespace App\Policies\ReportingAndAnalytics;

use App\Models\ReportingAndAnalytics\ReportExport;
use App\Models\User;

final readonly class ReportExportPolicy
{
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
        return $this->view($user, $export);
    }

    public function share(User $user, ReportExport $export): bool
    {
        return $this->owns($user, $export) && $this->allowed($user, 'reports.share');
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
