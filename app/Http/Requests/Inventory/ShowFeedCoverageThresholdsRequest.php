<?php

namespace App\Http\Requests\Inventory;

use App\Models\Inventory\FeedStockOperation;
use App\Models\User;
use App\Services\Lots\LotsAuthorization;

final class ShowFeedCoverageThresholdsRequest extends FeedRecipeRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && ($user->can('viewAny', FeedStockOperation::class)
            || LotsAuthorization::allows($user, 'can_manage_alert_thresholds'));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
