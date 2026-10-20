<?php

namespace App\Http\Requests\Inventory;

final class ViewFeedRecipeRequest extends FeedRecipeRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('recipe')) ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
