<?php

namespace App\Http\Resources\ManagementPlans;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ManagementHistoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var object{ id: string, operation_id: string|null, occurred_at: string, type: string, module: string, entity: string, flock_id: string, plan_activity_id: string|null, plan_activity_title: string|null, responsible_user_id: int|null, responsible_name: string|null, provenance: string, origin_flock_id: string, module_data: string|array<string, mixed> } $data */
        $data = $this->resource;
        $moduleData = $data->module_data;
        if (is_string($moduleData)) {
            $moduleData = json_decode($moduleData, true, 512, JSON_THROW_ON_ERROR);
        }

        return [
            'id' => $data->id,
            'operation_id' => $data->operation_id,
            'type' => $data->type,
            'module' => $data->module,
            'source' => $data->entity,
            'flock_id' => $data->flock_id,
            'occurred_at' => $data->occurred_at,
            'plan_activity_id' => $data->plan_activity_id,
            'plan_activity_title' => $data->plan_activity_title,
            'performed' => true,
            'outside_plan' => $data->plan_activity_id === null,
            'responsible' => [
                'user_id' => $data->responsible_user_id,
                'name' => $data->responsible_name,
            ],
            'provenance' => [
                'kind' => $data->provenance,
                'flock_id' => $data->origin_flock_id,
            ],
            'module_data' => $moduleData,
        ];
    }
}
