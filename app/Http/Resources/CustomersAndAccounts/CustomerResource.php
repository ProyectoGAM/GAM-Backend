<?php

namespace App\Http\Resources\CustomersAndAccounts;

use App\Enums\CustomersAndAccounts\CustomerStatus;
use App\Models\CustomersAndAccounts\Customer;
use App\Services\CustomersAndAccounts\CustomerFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Customer */
final class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $model = $this->resource instanceof Customer ? $this->resource : (new Customer)->setRawAttributes($this->resource);
        $data = ['id' => $model->public_id, 'display_name' => $model->displayName(),
            ...$model->only(array_keys(CustomerFields::rules())), 'status' => $model->status->value,
            'created_at' => $model->created_at?->utc()->toIso8601String(), 'updated_at' => $model->updated_at?->utc()->toIso8601String(),
            'has_location' => $model->latitude !== null && $model->longitude !== null,
            'ready_for_delivery' => $model->status === CustomerStatus::Active && $model->latitude !== null && $model->longitude !== null && filled($model->address)];
        if ($model->relationLoaded('locality')) {
            $data['locality'] = $model->locality === null ? null : ['id' => $model->locality->id,
                'name' => $model->locality->name, 'department_id' => $model->locality->department_id];
        }
        if ($model->relationLoaded('account') && $request->user()?->can('account', Customer::class)) {
            $data['account'] = new CustomerAccountResource($model->account);
        }

        return $data;
    }
}
