<?php

namespace App\Modules\Lots\Http\Requests;

use App\Models\Lots\EggCollection;
use Illuminate\Validation\Validator;

final class CorrectEggCollectionRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('recoleccion')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'version_lote' => ['required', 'integer', 'min:1'],
            'cantidad_recolectada' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'cantidad' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'cantidad_descartada' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'motivo_descarte' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lineas' => ['sometimes', 'array', 'max:100'],
            'lineas.*.producto_id' => ['required', 'integer', 'exists:products,id'],
            'lineas.*.ubicacion_stock_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'lineas.*.cantidad' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'observaciones' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'motivo' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if (! $this->has('cantidad_recolectada') && ! $this->has('cantidad_descartada') && ! $this->has('lineas')) {
                return;
            }
            $record = $this->route('recoleccion');
            $gross = (int) ($this->input('cantidad_recolectada') ?? $this->input('cantidad') ?? ($record instanceof EggCollection ? $record->collected_quantity : 0));
            $discarded = (int) ($this->input('cantidad_descartada') ?? ($record instanceof EggCollection ? $record->discarded_quantity : 0));
            if ($this->has('lineas')) {
                $lines = $this->input('lineas', []);
                $sum = is_array($lines) ? array_sum(array_map(static fn (mixed $line): int => is_array($line) ? (int) ($line['cantidad'] ?? 0) : 0, $lines)) : 0;
                if ($gross < 1 || $discarded > $gross || $sum !== $gross - $discarded) {
                    $validator->errors()->add('lineas', 'La suma de las clasificaciones debe coincidir con la cantidad utilizable.');
                }
            } elseif ($gross < 1 || $discarded > $gross) {
                $validator->errors()->add('cantidad_recolectada', 'La cantidad recolectada y el descarte deben formar un total válido.');
            }
            $reason = $this->input('motivo_descarte') ?? ($record instanceof EggCollection ? $record->discard_reason : null);
            if ($discarded > 0 && trim((string) $reason) === '') {
                $validator->errors()->add('motivo_descarte', 'Debes indicar el motivo del descarte.');
            }
        }];
    }
}
