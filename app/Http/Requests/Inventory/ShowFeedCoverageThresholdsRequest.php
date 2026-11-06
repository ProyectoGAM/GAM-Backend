<?php

namespace App\Http\Requests\Inventory;

use App\Models\Inventory\FeedStockOperation;

final class ShowFeedCoverageThresholdsRequest extends FeedRecipeRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', FeedStockOperation::class) ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
