<?php

namespace App\Http\Requests\Inventory;

use App\Models\User;
use App\ValueObjects\Inventory\RecipeGrams;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

abstract class FeedRecipeRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    abstract public function rules(): array;

    public function actor(): User
    {
        /** @var User $actor */
        $actor = $this->user();

        return $actor;
    }

    /** @return list<mixed> */
    protected function gramRules(): array
    {
        return ['bail', 'required', function (string $attribute, mixed $value, Closure $fail): void {
            try {
                RecipeGrams::from($value);
            } catch (InvalidArgumentException $exception) {
                $fail($exception->getMessage());
            }
        }];
    }

    /** @return array<string, list<mixed>> */
    protected function ingredientRules(): array
    {
        return [
            'ingredientes' => ['required', 'array', 'list', 'min:1', 'max:1000'],
            'ingredientes.*' => ['required', 'array:producto_id,cantidad_g'],
            'ingredientes.*.producto_id' => ['bail', 'required', 'integer', 'min:1', 'max:'.PHP_INT_MAX, 'distinct'],
            'ingredientes.*.cantidad_g' => $this->gramRules(),
        ];
    }

    /** @return list<array{product_id: int, quantity_grams: string}> */
    public function ingredientsForAction(): array
    {
        $ingredients = [];
        foreach ($this->validated('ingredientes') as $ingredient) {
            $ingredients[] = [
                'product_id' => (int) $ingredient['producto_id'],
                'quantity_grams' => (string) RecipeGrams::from($ingredient['cantidad_g'])->value,
            ];
        }

        return $ingredients;
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_keys($this->all()) as $key) {
                if (! array_key_exists($key, $this->rules())) {
                    $validator->errors()->add($key, 'El campo no está permitido en esta operación.');
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nombre.required' => 'Debes indicar el nombre de la receta.',
            'nombre.string' => 'El nombre debe ser un texto.',
            'nombre.max' => 'El nombre no puede superar los 160 caracteres.',
            'nota.string' => 'La nota debe ser un texto.',
            'nota.max' => 'La nota no puede superar los 5000 caracteres.',
            'ingredientes.required' => 'Debes indicar los ingredientes de la receta.',
            'ingredientes.array' => 'Los ingredientes deben ser una lista.',
            'ingredientes.list' => 'Los ingredientes deben ser una lista consecutiva.',
            'ingredientes.min' => 'La receta debe tener al menos un ingrediente.',
            'ingredientes.max' => 'La receta no puede superar los 1000 ingredientes.',
            'ingredientes.*.required' => 'Debes indicar el ingrediente.',
            'ingredientes.*.array' => 'Cada ingrediente sólo admite producto_id y cantidad_g.',
            'ingredientes.*.producto_id.required' => 'Debes indicar el producto de cada ingrediente.',
            'ingredientes.*.producto_id.integer' => 'El identificador del producto debe ser un entero.',
            'ingredientes.*.producto_id.min' => 'El identificador del producto debe ser positivo.',
            'ingredientes.*.producto_id.max' => 'El identificador del producto supera el límite permitido.',
            'ingredientes.*.producto_id.distinct' => 'Cada ingrediente debe aparecer una sola vez.',
            'ingredientes.*.producto_id.exists' => 'El ingrediente debe existir en el catálogo de materias primas.',
            'ingredientes.*.cantidad_g.required' => 'Debes indicar los gramos de cada ingrediente.',
            'rendimiento_g.required' => 'Debes indicar el rendimiento base en gramos.',
            'cantidad_g.required' => 'Debes indicar la cantidad solicitada en gramos.',
            'estado.required' => 'Debes indicar el estado de la receta.',
            'estado.enum' => 'El estado debe ser active o inactive.',
            'idempotency_key.required' => 'El encabezado Idempotency-Key es obligatorio.',
            'idempotency_key.uuid' => 'El encabezado Idempotency-Key debe ser un UUID válido.',
            'buscar.string' => 'La búsqueda debe ser un texto.',
            'buscar.max' => 'La búsqueda no puede superar los 120 caracteres.',
            'por_pagina.integer' => 'La cantidad por página debe ser un entero.',
            'por_pagina.min' => 'La cantidad por página debe ser al menos 1.',
            'por_pagina.max' => 'La cantidad por página no puede superar 100.',
            'page.integer' => 'La página debe ser un entero.',
            'page.min' => 'La página debe ser positiva.',
            'page.max' => 'La página supera el límite permitido.',
        ];
    }
}
