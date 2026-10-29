<?php

namespace App\Http\Requests\Inventory;

final class UpdateFeedRecipeQuantitiesRequest extends FeedRecipeRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('recipe')) ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return $this->ingredientRules();
    }
}
