<?php

namespace App\Queries\Lots;

use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use App\Models\Lots\MortalityRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as SqlBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final readonly class GetMortalityReferencePopulationQuery
{
    public function forPeriod(CarbonImmutable $start, CarbonImmutable $end, ?int $unitId): SqlBuilder
    {
        $startAt = $start->toIso8601String();
        $endAt = $end->toIso8601String();
        $flocks = Flock::query()->where('established_at', '<', $endAt)
            ->when($unitId !== null, fn (Builder $query): Builder => $query->where('production_unit_id', $unitId))
            ->select(['id', 'public_id', 'production_unit_id', 'established_at'])->toBase();

        $opening = $this->movementsForFlock()->where('occurred_at', '<', $startAt)
            ->selectRaw('"after" -> flock.public_id ->> \'current_quantity\' AS quantity')
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(1)->toBase();

        $establishment = FlockMovement::query()->whereColumn('destination_flock_id', 'flock.id')
            ->whereColumn('occurred_at', 'flock.established_at')->where('occurred_at', '>=', $startAt)
            ->whereIn('type', ['admission', 'partial_new'])
            ->select(['type', 'quantity'])->orderBy('id')->limit(1)->toBase();

        return DB::query()->fromSub($flocks, 'flock')
            ->leftJoinLateral($opening, 'opening')
            ->leftJoinLateral($establishment, 'establishment')
            ->leftJoinLateral($this->retroactiveCorrections($startAt), 'corrections')
            ->selectRaw(<<<'SQL'
                CASE WHEN COUNT(*) FILTER (WHERE
                    (flock.established_at < ? AND (
                        opening.quantity IS NULL OR opening.quantity !~ '^-?[0-9]+$' OR corrections.incomplete > 0
                    )) OR (flock.established_at >= ? AND establishment.type IS NULL)
                ) > 0 THEN NULL
                ELSE COALESCE(SUM(CASE
                    WHEN flock.established_at < ? AND opening.quantity ~ '^-?[0-9]+$' THEN GREATEST(0, opening.quantity::numeric + corrections.delta)
                    WHEN establishment.type = 'admission' THEN establishment.quantity
                    ELSE 0
                END), 0) END
                SQL, [$startAt, $startAt, $startAt]);
    }

    /** @return Builder<FlockMovement> */
    private function movementsForFlock(): Builder
    {
        return FlockMovement::query()->where(fn (Builder $query): Builder => $query
            ->whereColumn('source_flock_id', 'flock.id')->orWhereColumn('destination_flock_id', 'flock.id'));
    }

    private function retroactiveCorrections(string $startAt): SqlBuilder
    {
        $movements = $this->movementsForFlock()
            ->where('flock.established_at', '<', $startAt)
            ->where('flock_movements.type', 'mortality_correction')->where('flock_movements.occurred_at', '>=', $startAt)
            ->leftJoin('activity_log as audit', function (JoinClause $join): void {
                $join->on('audit.operation_id', '=', 'flock_movements.operation_id')
                    ->on('audit.up_id', '=', 'flock.production_unit_id')
                    ->where('audit.subject_type', MortalityRecord::class)
                    ->whereIn('audit.event', ['mortality_corrected', 'mortality_cancelled']);
            })
            ->selectRaw($this->deathsBeforeOpening('old').' AS previous, '.$this->deathsBeforeOpening('new').' AS revised', [$startAt, $startAt])
            ->toBase();

        // El censo de apertura se ajusta sólo por las bajas efectivas anteriores al inicio.
        return DB::query()->fromSub($movements, 'changes')
            ->selectRaw('COALESCE(SUM(previous - revised), 0) AS delta, COUNT(*) FILTER (WHERE previous IS NULL OR revised IS NULL) AS incomplete');
    }

    private function deathsBeforeOpening(string $version): string
    {
        $snapshot = "audit.attribute_changes -> '{$version}'";

        return <<<SQL
            CASE WHEN ({$snapshot} ->> 'status') IN ('recorded', 'cancelled')
                AND ({$snapshot} ->> 'quantity') ~ '^[0-9]+$'
                AND pg_input_is_valid({$snapshot} ->> 'occurred_at', 'timestamp with time zone')
            THEN CASE WHEN ({$snapshot} ->> 'status') = 'recorded'
                AND ({$snapshot} ->> 'occurred_at')::timestamptz < ?
            THEN ({$snapshot} ->> 'quantity')::numeric ELSE 0 END END
            SQL;
    }
}
