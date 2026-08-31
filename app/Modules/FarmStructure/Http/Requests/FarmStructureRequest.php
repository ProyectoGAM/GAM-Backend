<?php

namespace App\Modules\FarmStructure\Http\Requests;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

abstract class FarmStructureRequest extends FormRequest
{
    protected function authorizeProductionUnit(string $ability): bool
    {
        $actor = $this->user();
        $unidadProductiva = $this->route('unidadProductiva');

        if (! $actor instanceof User || ! $unidadProductiva instanceof ProductionUnit) {
            return false;
        }

        return $actor->can($ability, $unidadProductiva);
    }

    protected function authorizePoultryHouse(string $ability): bool
    {
        $actor = $this->user();
        $poultryHouse = $this->route('poultryHouse');

        if (! $actor instanceof User || ! $poultryHouse instanceof PoultryHouse) {
            return false;
        }

        return $actor->can($ability, $poultryHouse);
    }

    protected function authorizePoultryHouseCollection(string $ability): bool
    {
        $actor = $this->user();
        $unidadProductiva = $this->route('unidadProductiva');

        if (! $actor instanceof User || ! $unidadProductiva instanceof ProductionUnit) {
            return false;
        }

        if ($ability === 'viewAny') {
            return $actor->can($ability, PoultryHouse::class);
        }

        return $actor->can($ability, [PoultryHouse::class, $unidadProductiva]);
    }
}
