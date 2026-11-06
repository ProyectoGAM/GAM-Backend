<?php

namespace App\Http\Requests\Inventory;

use App\Models\Inventory\FeedStockOperation;
use App\Models\User;
use App\Services\Lots\LotsAuthorization;

final class UpdateFeedCoverageThresholdsRequest extends FeedRecipeRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && ($user->can('manageMinimums', FeedStockOperation::class)
            || LotsAuthorization::allows($user, 'can_manage_alert_thresholds'));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'dias_aviso' => ['required', 'integer', 'min:1', 'max:365'],
            'dias_urgencia' => ['required', 'integer', 'min:0', 'lt:dias_aviso'],
            'dias_aviso_bajo' => ['sometimes', 'nullable', 'integer', 'gt:dias_aviso', 'max:365'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'dias_urgencia.lt' => 'Los días de urgencia deben ser menores que los días de aviso.',
            'dias_aviso_bajo.gt' => 'Los días de aviso bajo deben ser mayores que los días de aviso medio.',
        ];
    }
}
