<?php

namespace App\Http\Requests\Inventory;

use App\ValueObjects\Inventory\FeedStockGrams;
use Closure;
use InvalidArgumentException;

final class SetFeedBalanceMinimumRequest extends FeedRecipeRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor !== null && ($actor->hasRole('admin') || $actor->can('inventory.manage'));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'minimo_g' => ['bail', 'present', 'nullable', function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    $minimum = FeedStockGrams::parse($value);
                    if ($minimum < 0) {
                        $fail('El mínimo no puede ser negativo.');
                    }
                } catch (InvalidArgumentException) {
                    $fail('El mínimo debe expresarse en gramos enteros entre 0 y 999999999999.');
                }
            }],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...parent::messages(), 'minimo_g.present' => 'Debes indicar el mínimo en gramos o null para quitarlo.'];
    }
}
