<?php

namespace App\Modules\Lots\Application\Services;

use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use App\Modules\FarmStructure\Application\PublicApi\Data\LockedPoultryHouseData;
use App\Modules\Lots\Domain\Enums\FlockStatus;
use App\Modules\Lots\Domain\Exceptions\LotsConflict;
use App\Shared\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class FlockState
{
    public function __construct(private Clock $clock) {}

    /**
     * @param  list<string>  $ids
     * @return Collection<string, Flock>
     */
    public function lock(array $ids): Collection
    {
        $ids = array_values(array_unique($ids));
        $flocks = Flock::query()->whereIn('public_id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');
        if ($flocks->count() !== count($ids)) {
            throw (new ModelNotFoundException)->setModel(Flock::class, $ids);
        }

        return $flocks;
    }

    public function version(Flock $flock, int $expected): void
    {
        if ($flock->version !== $expected) {
            throw new LotsConflict('La versión del lote cambió. Actualiza los datos antes de reintentar.');
        }
    }

    public function open(Flock $flock, bool $requireActive = false): void
    {
        if ($flock->status === FlockStatus::Finished || ($requireActive && $flock->status !== FlockStatus::Active)) {
            throw new LotsConflict('El estado del lote no permite esta operación.');
        }
    }

    public function positive(int $quantity): void
    {
        if ($quantity < 1 || $quantity > 2147483647) {
            throw new LotsConflict('La cantidad debe ser un entero positivo dentro del límite permitido.');
        }
    }

    public function receive(LockedPoultryHouseData $house, int $netIncrease): void
    {
        if (! $house->canReceive) {
            throw new LotsConflict('El galpón destino y su unidad productiva deben estar operativos.');
        }
        if (! $house->supports($netIncrease)) {
            throw new LotsConflict('La redistribución supera la capacidad disponible del galpón.');
        }
    }

    public function time(Flock $flock, ?string $occurredAt = null): CarbonImmutable
    {
        $time = $occurredAt === null ? $this->clock->now() : CarbonImmutable::parse($occurredAt)->utc();
        if ($time->greaterThan($this->clock->now()) || $time->lessThan($flock->established_at)) {
            throw new LotsConflict('La fecha debe pertenecer a la existencia del lote y no puede ser futura.');
        }
        $last = FlockMovement::query()->where(fn ($query) => $query->where('source_flock_id', $flock->id)
            ->orWhere('destination_flock_id', $flock->id))->max('occurred_at');
        if (is_string($last) && $time->lessThan(CarbonImmutable::parse($last))) {
            throw new LotsConflict('Existen movimientos posteriores a la fecha indicada. Revisa el historial del lote.');
        }

        return $time;
    }
}
