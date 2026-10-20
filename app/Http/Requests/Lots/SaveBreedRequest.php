<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\Breed;
use Brick\Math\BigDecimal;
use Illuminate\Validation\Validator;

final class SaveBreedRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->isMethod('POST') ? ($this->user()?->can('create', Breed::class) ?? false) : ($this->user()?->can('update', $this->route('breed')) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(! $this->isMethod('POST')),
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:120'],
            'status' => ['sometimes', 'in:active,inactive'],
            'chick_min_weight_g' => $this->weightRules(),
            'chick_max_weight_g' => $this->weightRules(),
            'adult_min_weight_g' => $this->weightRules(),
            'adult_max_weight_g' => $this->weightRules(),
        ];
    }

    /** @return list<string> */
    private function weightRules(): array
    {
        return ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{1,13}(?:\.[0-9])?$/'];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                foreach (['chick' => 'pollitos', 'adult' => 'adultas'] as $stage => $label) {
                    $minimumField = $stage.'_min_weight_g';
                    $maximumField = $stage.'_max_weight_g';
                    $minimumProvided = array_key_exists($minimumField, $this->all());
                    $maximumProvided = array_key_exists($maximumField, $this->all());
                    if ($minimumProvided !== $maximumProvided) {
                        $validator->errors()->add($minimumField, 'Indica ambos límites de '.$label.' o deja ambos vacíos.');

                        continue;
                    }
                    if (! $minimumProvided || $validator->errors()->hasAny([$minimumField, $maximumField])) {
                        continue;
                    }
                    $minimum = $this->input($minimumField);
                    $maximum = $this->input($maximumField);
                    if ($minimum === null && $maximum === null) {
                        continue;
                    }
                    if (! is_string($minimum) || ! is_string($maximum)) {
                        $validator->errors()->add($minimumField, 'Indica ambos límites de '.$label.' o deja ambos vacíos.');

                        continue;
                    }
                    if (BigDecimal::of($minimum)->compareTo('0') <= 0 || BigDecimal::of($minimum)->compareTo($maximum) >= 0) {
                        $validator->errors()->add($minimumField, 'El mínimo de '.$label.' debe ser positivo y menor que su máximo.');
                    }
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'chick_min_weight_g.regex' => 'Los gramos admiten como máximo un decimal y trece dígitos enteros.',
            'chick_max_weight_g.regex' => 'Los gramos admiten como máximo un decimal y trece dígitos enteros.',
            'adult_min_weight_g.regex' => 'Los gramos admiten como máximo un decimal y trece dígitos enteros.',
            'adult_max_weight_g.regex' => 'Los gramos admiten como máximo un decimal y trece dígitos enteros.',
        ];
    }
}
