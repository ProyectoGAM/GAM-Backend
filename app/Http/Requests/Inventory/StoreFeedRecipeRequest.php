<?php

namespace App\Http\Requests\Inventory;

use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Models\Inventory\FeedRecipe;
use App\ValueObjects\Inventory\RecipeGrams;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreFeedRecipeRequest extends FeedRecipeRequest
{
    private bool $submittedIdempotencyKey = false;

    protected function prepareForValidation(): void
    {
        $this->submittedIdempotencyKey = $this->has('idempotency_key');
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', FeedRecipe::class) ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $rules = [
            'nombre' => ['required', 'string', 'max:160'],
            'nota' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'rendimiento_g' => $this->gramRules(),
            'idempotency_key' => ['required', 'uuid'],
            ...$this->ingredientRules(),
        ];
        $rules['ingredientes.*.producto_id'][] = Rule::exists('products', 'id')->where('kind', ProductKind::RawMaterial->value);

        return $rules;
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($this->submittedIdempotencyKey) {
                $validator->errors()->add('idempotency_key', 'La clave de idempotencia sólo se admite en el encabezado Idempotency-Key.');
            }
        }];
    }

    /** @return array{name: string, notes: string|null, yield_grams: string, ingredients: list<array{product_id: int, quantity_grams: string}>, idempotency_key: string} */
    public function attributesForAction(): array
    {
        return [
            'name' => $this->validated('nombre'), 'notes' => $this->validated('nota'),
            'yield_grams' => (string) RecipeGrams::from($this->validated('rendimiento_g'))->value,
            'ingredients' => $this->ingredientsForAction(), 'idempotency_key' => $this->validated('idempotency_key'),
        ];
    }
}
