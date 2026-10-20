<?php

namespace App\Http\Requests\Inventory;

final class CalculateFeedRecipeRequest extends FeedRecipeRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('calculate', $this->route('recipe')) ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['cantidad_g' => $this->gramRules()];
    }
}
