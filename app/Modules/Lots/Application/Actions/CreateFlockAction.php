<?php

namespace App\Modules\Lots\Application\Actions;

use App\Models\Lots\Breed;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\User;
use App\Modules\FarmStructure\Application\PublicApi\Queries\LockPoultryHousesQuery;
use App\Modules\Lots\Application\Services\FlockState;
use App\Modules\Lots\Application\Services\LotsHistory;
use App\Modules\Lots\Application\Services\LotsSnapshots;
use App\Modules\Lots\Application\Services\RunLotsCommand;
use App\Modules\Lots\Domain\Enums\FlockStatus;
use App\Modules\Lots\Domain\Events\FlockCreated;
use App\Modules\Lots\Domain\Exceptions\LotsConflict;
use App\Modules\SuppliersAndCatalogs\Application\PublicApi\Queries\GetActiveSupplierQuery;
use App\Shared\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class CreateFlockAction
{
    public function __construct(private RunLotsCommand $commands, private FlockState $state, private LockPoultryHousesQuery $houses, private GetActiveSupplierQuery $suppliers, private LotsSnapshots $snapshots, private LotsHistory $history, private Clock $clock) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor, string $source = 'api'): FlockOperation
    {
        $data['code'] = Str::upper(trim($data['code']));

        return $this->commands->execute($actor, 'flocks.manage', 'flock.create', $data['idempotency_key'], $data, function (string $operationId) use ($data, $actor, $source): array {
            $quantity = (int) $data['initial_quantity'];
            $this->state->positive($quantity);
            $entry = CarbonImmutable::createFromFormat('!Y-m-d', $data['entry_date'], config('lots.timezone'));
            if ($entry === null || $entry->format('Y-m-d') !== $data['entry_date'] || $entry->greaterThan($this->clock->now())) {
                throw new LotsConflict('La fecha de ingreso no es válida o es futura.');
            }
            $breed = Breed::query()->sharedLock()->findOrFail($data['breed_id']);
            if ($breed->status !== 'active') {
                throw new LotsConflict('La raza debe estar activa.');
            }
            $supplier = isset($data['supplier_id']) ? $this->suppliers->execute((int) $data['supplier_id']) : null;
            if ((isset($data['supplier_id']) && $supplier === null) || ($supplier === null && trim($data['origin'] ?? '') === '')) {
                throw new LotsConflict('Indica un proveedor activo o describe el origen del lote.');
            }
            $house = $this->houses->execute([(int) $data['poultry_house_id']])[(int) $data['poultry_house_id']];
            $this->state->receive($house, $quantity);
            $flock = new Flock;
            $flock->forceFill([
                'public_id' => $data['public_id'] ?? (string) Str::ulid(), 'code' => $data['code'],
                'breed_id' => $breed->id, 'supplier_id' => $supplier?->id, 'supplier_name' => $supplier?->name,
                'origin' => $data['origin'] ?? null, 'poultry_house_id' => $house->id, 'production_unit_id' => $house->productionUnitId,
                'initial_quantity' => $quantity, 'current_quantity' => $quantity, 'entry_date' => $data['entry_date'],
                'established_at' => $entry->utc(), 'status' => FlockStatus::Active, 'version' => 1, 'notes' => $data['notes'] ?? null,
            ])->save();
            $after = $this->snapshots->flock($flock);
            $movement = $this->history->movement($operationId, 'admission', null, $flock, $quantity, [], [$flock->public_id => $after], $entry->utc(), $actor);
            $this->history->audit($flock, $actor, 'flock_created', 'Lote creado', $operationId, [], $after, $house->productionUnitId, $source);
            event(new FlockCreated($operationId, [$flock->public_id], $actor->id));

            return ['flock' => $after, 'movement' => $this->snapshots->movement($movement)];
        });
    }
}
