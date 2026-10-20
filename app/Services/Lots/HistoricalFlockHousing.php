<?php

namespace App\Services\Lots;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class HistoricalFlockHousing
{
    /** El alias r debe publicar date y poultry_house_id; f identifica el lote del hecho. */
    public function join(Builder $query): Builder
    {
        $expression = "COALESCE(r.poultry_house_id, (SELECT (m.after -> f.public_id ->> 'poultry_house_id')::bigint FROM flock_movements m WHERE (m.source_flock_id = f.id OR m.destination_flock_id = f.id) AND m.occurred_at <= r.date AND jsonb_exists(m.after, f.public_id) ORDER BY m.occurred_at DESC, m.id DESC LIMIT 1))";

        return $query->leftJoin('poultry_houses as h', 'h.id', '=', DB::raw($expression))
            ->leftJoin('production_units as u', 'u.id', '=', 'h.production_unit_id');
    }
}
