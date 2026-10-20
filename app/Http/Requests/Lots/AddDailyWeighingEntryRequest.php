<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\Weighing;
use Illuminate\Validation\Validator;

final class AddDailyWeighingEntryRequest extends WeighingsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Weighing::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(false),
            'mode' => ['required', 'in:individual,group'],
            'weight' => ['required_if:mode,individual', 'prohibited_unless:mode,individual', 'string', 'regex:'.$this->decimalPattern(1)],
            'bird_count' => ['required_if:mode,group', 'prohibited_unless:mode,group', 'integer', 'min:1', 'max:2147483647'],
            'total_weight' => ['required_if:mode,group', 'prohibited_unless:mode,group', 'string', 'regex:'.$this->decimalPattern(1)],
            'confirm_out_of_range' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            foreach (['weight', 'total_weight'] as $field) {
                $value = $this->input($field);
                if (is_string($value) && strlen(explode('.', $value)[0]) > 13) {
                    $validator->errors()->add($field, 'Los gramos no pueden superar trece dígitos enteros.');
                }
            }
        }];
    }
}
