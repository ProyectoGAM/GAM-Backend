<?php

namespace App\Policies\ReportingAndAnalytics;

use App\Models\ReportingAndAnalytics\ReportPreset;
use App\Models\User;

final readonly class ReportPresetPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allowed($user);
    }

    public function view(User $user, ReportPreset $preset): bool
    {
        return $this->owns($user, $preset) && $this->allowed($user);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user);
    }

    public function update(User $user, ReportPreset $preset): bool
    {
        return $this->owns($user, $preset) && $this->allowed($user);
    }

    public function delete(User $user, ReportPreset $preset): bool
    {
        return $this->owns($user, $preset) && $this->allowed($user);
    }

    private function allowed(User $user): bool
    {
        return $user->hasRole('admin') || $user->checkPermissionTo('reports.presets.manage');
    }

    private function owns(User $user, ReportPreset $preset): bool
    {
        return (int) $preset->user_id === (int) $user->getKey();
    }
}
