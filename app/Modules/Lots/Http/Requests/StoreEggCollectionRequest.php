<?php

namespace App\Modules\Lots\Http\Requests;

use App\Models\Lots\EggCollection;

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
            'cantidad' => $this->quantityRules(),
            'producto_id' => ['required', 'integer', 'min:1'],
            'ubicacion_stock_id' => ['required', 'integer', 'min:1'],
            'ocurrido_en' => $this->timeRules(),
            'observaciones' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
