<?php

namespace App\Modules\FarmStructure\Http\Requests;

use App\Models\FarmStructure\Maintenance;

final class StoreMaintenanceRequest extends MaintenanceRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Maintenance::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->maintenanceRules() + ['idempotency_key' => ['required', 'uuid']];
    }
}
