<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\Weighing;
use Illuminate\Validation\Validator;

abstract class WeighingsRequest extends LotsRequest
{
    /** @return array<string, mixed> */
    public function attributesForAction(): array
    {
        $data = parent::attributesForAction();
        if (array_key_exists('confirm_out_of_range', $data)) {
            $data['confirm_out_of_range'] = (bool) $data['confirm_out_of_range'];
        }

        return $data;
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                $mode = $this->effectiveMode();
                $unit = $this->effectiveUnit();
                $maximumDecimals = $unit === 'kg' ? 4 : 1;
                $maximumIntegerDigits = $this->maximumIntegerDigits($unit);
                foreach ($this->input('measurements', []) as $index => $measurement) {
                    if (! is_array($measurement)) {
                        continue;
                    }
                    $allowed = $mode === 'individual'
                        ? ['weight']
                        : ($mode === 'group' ? ['total_weight', 'bird_count'] : ['weight', 'total_weight', 'bird_count']);
                    foreach (array_keys($measurement) as $key) {
                        if (! in_array($key, $allowed, true)) {
                            $validator->errors()->add("measurements.$index.$key", 'El campo no está permitido para este modo de pesaje.');
                        }
                    }
                    foreach (array_intersect(array_keys($measurement), ['weight', 'total_weight']) as $key) {
                        $value = $measurement[$key];
                        if (! is_string($value)) {
                            continue;
                        }
                        if (preg_match('/^0(?:\.0+)?$/D', $value) === 1) {
                            $validator->errors()->add("measurements.$index.$key", 'El peso debe ser mayor que cero.');
                        }
                        if (str_contains($value, '.')) {
                            $decimals = strlen((string) strrchr($value, '.')) - 1;
                            if ($decimals > $maximumDecimals) {
                                $validator->errors()->add("measurements.$index.$key", $unit === 'kg'
                                    ? 'Los kilogramos admiten como máximo cuatro decimales.'
                                    : 'Los gramos admiten como máximo un decimal.');
                            }
                        }
                        $integerPart = str_contains($value, '.') ? (string) strstr($value, '.', true) : $value;
                        if (strlen($integerPart) > $maximumIntegerDigits) {
                            $validator->errors()->add(
                                "measurements.$index.$key",
                                $this->decimalMagnitudeMessage($unit),
                            );
                        }
                    }
                }
            },
        ];
    }

    /** @return list<string> */
    protected function decimalRules(int $maxDecimals): array
    {
        return ['required', 'string', 'regex:'.$this->decimalPattern($maxDecimals)];
    }

    /** @return list<string> */
    protected function optionalDecimalRules(int $maxDecimals): array
    {
        return ['sometimes', 'string', 'regex:'.$this->decimalPattern($maxDecimals)];
    }

    protected function decimalPattern(int $maxDecimals): string
    {
        return '/\A(?=.*[1-9])(?:0|[1-9]\d{0,12})(?:\.\d{1,'.$maxDecimals.'})?\z/';
    }

    protected function maximumIntegerDigits(string $unit): int
    {
        return $unit === 'kg' ? 10 : 13;
    }

    protected function decimalMagnitudeMessage(string $unit): string
    {
        return $unit === 'kg'
            ? 'Los kilogramos no pueden superar diez dígitos enteros al convertirse a gramos.'
            : 'Los gramos no pueden superar trece dígitos enteros.';
    }

    protected function effectiveMode(): ?string
    {
        $mode = $this->input('mode');
        if (is_string($mode) && $mode !== '') {
            return $mode;
        }

        $weighing = $this->route('pesaje');

        return $weighing instanceof Weighing ? $weighing->mode : null;
    }

    protected function effectiveUnit(): string
    {
        $unit = $this->input('unit');
        if (is_string($unit) && $unit !== '') {
            return $unit;
        }

        $weighing = $this->route('pesaje');

        return $weighing instanceof Weighing ? $weighing->captured_unit : 'g';
    }
}
