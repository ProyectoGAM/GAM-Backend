<?php

namespace App\Http\Requests\Inventory;

use App\Enums\Inventory\FeedRecipeStatus;
use App\Models\Inventory\FeedRecipe;
use Illuminate\Validation\Rule;

final class ListFeedRecipesRequest extends FeedRecipeRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', FeedRecipe::class) ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'buscar' => ['sometimes', 'string', 'max:120'],
            'estado' => ['sometimes', Rule::enum(FeedRecipeStatus::class)],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ];
    }
}
