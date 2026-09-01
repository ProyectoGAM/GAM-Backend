<?php

namespace App\Modules\Lots\Http\Requests;

use App\Models\Lots\EggCollection;
use Illuminate\Validation\Validator;

final class StoreEggCollectionRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EggCollection::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'id' => ['sometimes', 'ulid'],
            'cantidad_recolectada' => ['sometimes', ...$this->quantityRules()],
            'cantidad' => ['sometimes', ...$this->quantityRules()],
            'cantidad_descartada' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'motivo_descarte' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lineas' => ['sometimes', 'array', 'max:100'],
            'lineas.*.producto_id' => ['required', 'integer', 'exists:products,id'],
            'lineas.*.ubicacion_stock_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'lineas.*.cantidad' => $this->quantityRules(),
            // Se aceptan durante la transición para no romper capturas existentes del módulo de Lotes.
            'producto_id' => ['sometimes', 'integer', 'exists:products,id'],
            'ubicacion_stock_id' => ['sometimes', 'integer', 'exists:stock_locations,id'],
            'ocurrido_en' => $this->timeRules(),
            'observaciones' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            $gross = (int) ($this->input('cantidad_recolectada') ?? $this->input('cantidad', 0));
            $discarded = (int) $this->input('cantidad_descartada', 0);
            $lines = $this->input('lineas', []);
            if ($gross < 1) {
                $validator->errors()->add('cantidad_recolectada', 'Debes indicar la cantidad recolectada.');
            }
            if ($discarded > 0 && trim((string) $this->input('motivo_descarte', '')) === '') {
                $validator->errors()->add('motivo_descarte', 'Debes indicar el motivo del descarte.');
            }
            if ($lines === [] && $this->filled('producto_id') && $this->filled('ubicacion_stock_id')) {
                $lines = [['cantidad' => $gross - $discarded]];
            }
            $sum = is_array($lines) ? array_sum(array_map(static fn (mixed $line): int => is_array($line) ? (int) ($line['cantidad'] ?? 0) : 0, $lines)) : 0;
            if ($sum !== $gross - $discarded) {
                $validator->errors()->add('lineas', 'La suma de las clasificaciones debe coincidir con la cantidad utilizable.');
            }
        }];
    }

    /** @return array<string, mixed> */
    public function attributesForAction(): array
    {
        $data = parent::attributesForAction();
        if (! isset($data['collected_quantity']) && isset($data['quantity'])) {
            $data['collected_quantity'] = $data['quantity'];
        }
        $collected = (int) ($data['collected_quantity'] ?? $data['quantity'] ?? 0);
        if (! isset($data['lines']) && isset($data['product_id'], $data['stock_location_id']) && $collected > (int) ($data['discarded_quantity'] ?? 0)) {
            $data['lines'] = [[
                'product_id' => $data['product_id'],
                'stock_location_id' => $data['stock_location_id'],
                'quantity' => $collected - (int) ($data['discarded_quantity'] ?? 0),
            ]];
        }

        return $data;
    }
}
