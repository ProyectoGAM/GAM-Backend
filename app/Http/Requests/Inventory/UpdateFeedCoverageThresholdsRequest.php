<?php

namespace App\Http\Requests\Inventory;

use App\Models\Inventory\FeedStockOperation;

final class UpdateFeedCoverageThresholdsRequest extends FeedRecipeRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageMinimums', FeedStockOperation::class) ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'dias_aviso' => ['required', 'integer', 'min:1', 'max:365'],
            'dias_urgencia' => ['required', 'integer', 'min:0', 'lt:dias_aviso'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'dias_urgencia.lt' => 'Los días de urgencia deben ser menores que los días de aviso.',
        ];
    }
}
