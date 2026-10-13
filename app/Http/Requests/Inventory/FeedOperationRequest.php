<?php

namespace App\Http\Requests\Inventory;

use App\ValueObjects\Inventory\FeedStockGrams;
use Closure;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

abstract class FeedOperationRequest extends FeedRecipeRequest
{
    private bool $submittedIdempotencyKey = false;

    protected function prepareForValidation(): void
    {
        if ($this->isMethod('POST')) {
            $this->submittedIdempotencyKey = $this->has('idempotency_key');
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    /** El permiso funcional específico sigue pendiente; conserva la sesión autenticada. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return list<mixed> */
    protected function stockGramRules(bool $positive = true, bool $allowNegative = true): array
    {
        return ['bail', 'required', function (string $attribute, mixed $value, Closure $fail) use ($positive, $allowNegative): void {
            try {
                FeedStockGrams::parse($value, $positive, $allowNegative);
            } catch (InvalidArgumentException $exception) {
                $fail($exception->getMessage());
            }
        }];
    }

    /** @return list<mixed> */
    protected function identifierRules(bool $required = false): array
    {
        return [$required ? 'required' : 'sometimes', 'integer', 'min:1', 'max:'.PHP_INT_MAX];
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

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'receta_id.required' => 'Debes indicar la receta.',
            'planta_id.required' => 'Debes indicar la planta de ración.',
            '*.integer' => 'El identificador o la página debe ser un entero.',
            '*.min' => 'El identificador o la página debe ser positivo.',
            '*.max' => 'El identificador o la página supera el límite permitido.',
            'motivo.required' => 'Debes indicar el motivo.',
            'motivo.string' => 'El motivo debe ser un texto.',
            'motivo.max' => 'El motivo no puede superar los 2000 caracteres.',
            'accion.required' => 'Debes indicar la acción del ajuste.',
            'accion.in' => 'La acción debe ser sumar, restar o establecer.',
            'valor_g.required' => 'Debes indicar el valor del ajuste en gramos.',
            'estado.in' => 'El estado debe ser elaborada o cancelada.',
            'tipo.in' => 'El tipo debe ser ingrediente o alimento.',
        ];
    }
}
