<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\WeighingReferenceSettings;
use Brick\Math\BigDecimal;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateWeighingSettingsRequest extends WeighingsRequest
{
    public function authorize(): bool
    {
        $settings = WeighingReferenceSettings::query()->first();

        return $settings === null
            ? ($this->user()?->can('viewAny', WeighingReferenceSettings::class) ?? false)
            : ($this->user()?->can('update', $settings) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(false),
            'version' => [
                Rule::requiredIf(WeighingReferenceSettings::query()->exists()),
                'integer',
                'min:1',
                'max:2147483647',
            ],
            'adult_from_week' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'unit' => ['required', 'in:g,kg'],
            'chick_min_weight' => $this->decimalRules(4),
            'chick_max_weight' => $this->decimalRules(4),
            'adult_min_weight' => $this->decimalRules(4),
            'adult_max_weight' => $this->decimalRules(4),
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                $unit = (string) $this->input('unit');
                $maximumDecimals = $unit === 'kg' ? 4 : 1;
                $maximumIntegerDigits = $this->maximumIntegerDigits($unit);
                $fields = [
                    'chick_min_weight',
                    'chick_max_weight',
                    'adult_min_weight',
                    'adult_max_weight',
                ];
                foreach ($fields as $field) {
                    $value = $this->input($field);
                    if (! is_string($value)) {
                        continue;
                    }
                    if (str_contains($value, '.') && strlen((string) strrchr($value, '.')) - 1 > $maximumDecimals) {
                        $validator->errors()->add($field, $unit === 'kg'
                            ? 'Los kilogramos admiten como máximo cuatro decimales.'
                            : 'Los gramos admiten como máximo un decimal.');
                    }
                    $integerPart = str_contains($value, '.') ? (string) strstr($value, '.', true) : $value;
                    if (strlen($integerPart) > $maximumIntegerDigits) {
                        $validator->errors()->add($field, $this->decimalMagnitudeMessage($unit));
                    }
                }

                if ($validator->errors()->hasAny($fields)) {
                    return;
                }

                $chickMinimum = BigDecimal::of((string) $this->input('chick_min_weight'));
                $chickMaximum = BigDecimal::of((string) $this->input('chick_max_weight'));
                $adultMinimum = BigDecimal::of((string) $this->input('adult_min_weight'));
                $adultMaximum = BigDecimal::of((string) $this->input('adult_max_weight'));
                if ($chickMinimum->compareTo('0') <= 0 || $chickMinimum->compareTo($chickMaximum) >= 0) {
                    $validator->errors()->add('chick_min_weight', 'El mínimo de pollitos debe ser positivo y menor que su máximo.');
                }
                if ($adultMinimum->compareTo('0') <= 0 || $adultMinimum->compareTo($adultMaximum) >= 0) {
                    $validator->errors()->add('adult_min_weight', 'El mínimo de adultas debe ser positivo y menor que su máximo.');
                }
            },
        ];
    }
}
