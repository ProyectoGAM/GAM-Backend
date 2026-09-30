<?php

namespace App\Http\Requests\ManagementPlans;

final class ShowMedicineStockRequest extends ManagementExecutionRequest
{
    public function authorize(): bool
    {
        return $this->authorizeMedicineStockRead();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }
}
