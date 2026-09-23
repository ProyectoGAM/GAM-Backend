<?php

namespace App\Http\Requests\ManagementPlans;

use App\Http\Requests\Lots\LotsRequest;
use App\Models\Lots\Flock;
use App\Models\SuppliersAndCatalogs\Medicine;
use App\Models\User;
use App\Services\Lots\LotsAuthorization;

abstract class ManagementExecutionRequest extends LotsRequest
{
    protected function authorizeFlockExecution(): bool
    {
        $flock = $this->route('flock');

        return $flock instanceof Flock
            && ($this->user() instanceof User)
            && LotsAuthorization::allows($this->user(), 'management-plans.execute');
    }

    protected function authorizeMedicineStockManagement(): bool
    {
        $medicine = $this->route('medicine');

        return $medicine instanceof Medicine
            && ($this->user() instanceof User)
            && LotsAuthorization::allows($this->user(), 'management-plans.stock.manage');
    }

    protected function authorizeMedicineStockRead(): bool
    {
        $medicine = $this->route('medicine');
        $user = $this->user();

        return $medicine instanceof Medicine
            && $user instanceof User
            && (LotsAuthorization::allows($user, 'management-plans.view')
                || LotsAuthorization::allows($user, 'management-plans.stock.manage'));
    }

    /** @return array<string, mixed> */
    protected function activityLinkRules(): array
    {
        return [
            'plan_activity_id' => ['sometimes', 'ulid'],
            'responsible_user_id' => ['sometimes', 'integer', 'min:1', 'max:9223372036854775807'],
            'occurred_at' => ['required', 'date', 'regex:/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(Z|[+-]\\d{2}:\\d{2})$/'],
        ];
    }
}
