<?php

namespace App\Modules\Lots\Http\Requests;

use Illuminate\Validation\Rule;

final class RedistributeFlockRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->flockAbility('redistribute');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'cantidad' => $this->quantityRules(),
            'galpon_destino_id' => ['required_without:lote_destino_id', 'prohibits:lote_destino_id', 'integer', 'min:1'],
            'lote_destino_id' => ['required_without:galpon_destino_id', 'prohibits:galpon_destino_id', 'ulid'],
            'version_destino' => ['required_with:lote_destino_id', Rule::prohibitedIf(! $this->filled('lote_destino_id')), 'integer', 'min:1'],
            'codigo_destino' => ['sometimes', 'prohibits:lote_destino_id', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'id_destino' => ['sometimes', 'prohibits:lote_destino_id', 'ulid'],
            'ocurrido_en' => $this->timeRules(),
            'motivo' => ['nullable', 'string', 'max:500'],
        ];
    }
}
