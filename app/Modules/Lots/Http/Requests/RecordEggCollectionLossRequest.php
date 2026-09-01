<?php

namespace App\Modules\Lots\Http\Requests;

use Illuminate\Validation\Validator;

final class RecordEggCollectionLossRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('recoleccion')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(version: false),
            'lineas' => ['required', 'array', 'min:1', 'max:100'],
            'lineas.*.producto_id' => ['required', 'integer', 'exists:products,id'],
            'lineas.*.ubicacion_stock_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'lineas.*.cantidad' => $this->quantityRules(),
            'motivo' => ['required', 'string', 'max:255'],
            'ocurrido_en' => ['sometimes', 'date'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            $keys = [];
            foreach ((array) $this->input('lineas', []) as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $key = ((int) ($line['producto_id'] ?? 0)).':'.((int) ($line['ubicacion_stock_id'] ?? 0));
                if (isset($keys[$key])) {
                    $validator->errors()->add('lineas', 'No puedes repetir una clasificación en la pérdida.');
                }
                $keys[$key] = true;
            }
        }];
    }
}
