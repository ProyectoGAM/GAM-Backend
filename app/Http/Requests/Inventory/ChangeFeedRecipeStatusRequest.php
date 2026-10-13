<?php

namespace App\Http\Requests\Inventory;

use App\Enums\Inventory\FeedRecipeStatus;
use Illuminate\Validation\Rule;

final class ChangeFeedRecipeStatusRequest extends FeedRecipeRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('changeStatus', $this->route('recipe')) ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['estado' => ['required', Rule::enum(FeedRecipeStatus::class)]];
    }
}
