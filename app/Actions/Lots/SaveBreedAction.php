<?php

namespace App\Actions\Lots;

use App\Exceptions\Lots\LotsConflict;
use App\Models\Lots\Breed;
use App\Models\Lots\FlockOperation;
use App\Models\Lots\WeighingReferenceSettings;
use App\Models\User;
use App\Services\Lots\BreedWeighingReference;
use App\Services\Lots\LotsHistory;
use App\Services\Lots\ReclassifyOpenDailyWeighings;
use App\Services\Lots\RunLotsCommand;
use App\Services\Lots\WeighingMath;
use Illuminate\Support\Facades\DB;

final readonly class SaveBreedAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private BreedWeighingReference $references,
        private ReclassifyOpenDailyWeighings $reclassifyOpenDailyWeighings,
        private WeighingMath $math,
        private LotsHistory $history,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(?Breed $record, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        return $this->commands->execute($actor, 'breeds.manage', 'breed.save', $data['idempotency_key'], ['id' => $record?->id, ...$data], function (string $operationId) use ($record, $data, $actor, $source): array {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("SELECT pg_advisory_xact_lock(hashtext('weighing_reference_settings'))");
            }
            $settings = WeighingReferenceSettings::query()->lockForUpdate()->first();
            $current = $record === null ? new Breed : Breed::query()->lockForUpdate()->findOrFail($record->id);
            $before = $record === null ? [] : $this->references->catalog($current, $settings);
            if ($record !== null && $current->version !== (int) $data['version']) {
                throw new LotsConflict('La versión del catálogo cambió. Actualiza los datos.');
            }
            if (isset($data['name'])) {
                if ($data['name'] === '') {
                    throw new LotsConflict('El nombre no puede estar vacío.');
                }
                $current->name = $data['name'];
            }

            $rangeChanged = false;
            foreach (['chick_min', 'chick_max', 'adult_min', 'adult_max'] as $field) {
                $name = $field.'_weight_g';
                if (array_key_exists($name, $data)) {
                    $current->{$name} = $data[$name] === null ? null : $this->math->toGrams((string) $data[$name], 'g');
                    $rangeChanged = true;
                }
            }
            foreach (['chick', 'adult'] as $stage) {
                $minimum = $current->{$stage.'_min_weight_g'};
                $maximum = $current->{$stage.'_max_weight_g'};
                if (($minimum === null) !== ($maximum === null)
                    || ($minimum !== null && ($this->math->compare((string) $minimum, '0') <= 0 || $this->math->compare((string) $minimum, (string) $maximum) >= 0))) {
                    throw new LotsConflict('El rango de la raza debe tener un mínimo positivo menor que su máximo, o ambos valores vacíos.', code: 'BREED_WEIGHING_RANGE_INVALID');
                }
            }

            $current->status = $data['status'] ?? ($record === null ? 'active' : $current->status);
            $current->version = $record === null ? 1 : $current->version + 1;
            $current->save();
            $after = $this->references->catalog($current, $settings);
            if ($record !== null && $rangeChanged && $settings !== null) {
                $this->reclassifyOpenDailyWeighings->execute($settings, $actor, $operationId, $source, $current->id);
            }
            $this->history->audit($current, $actor, $record === null ? 'breed_created' : 'breed_updated', 'Catálogo de lotes actualizado', $operationId, $before, $after, source: $source);

            return ['catalog' => $after];
        });
    }
}
