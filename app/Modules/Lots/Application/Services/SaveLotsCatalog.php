<?php

namespace App\Modules\Lots\Application\Services;

use App\Models\Lots\Breed;
use App\Models\Lots\FlockOperation;
use App\Models\Lots\MortalityCategory;
use App\Models\User;
use App\Modules\Lots\Domain\Exceptions\LotsConflict;

final readonly class SaveLotsCatalog
{
    public function __construct(private RunLotsCommand $commands, private LotsHistory $history) {}

    /**
     * Comparte el flujo transaccional de los dos catálogos propios del módulo.
     *
     * @param  class-string<Breed>|class-string<MortalityCategory>  $modelClass
     * @param  array<string, mixed>  $data
     */
    public function execute(string $modelClass, Breed|MortalityCategory|null $record, array $data, User $actor, string $permission, string $event, string $source): FlockOperation
    {
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        return $this->commands->execute($actor, $permission, $event.'.save', $data['idempotency_key'], ['id' => $record?->id, ...$data], function (string $operationId) use ($modelClass, $record, $data, $actor, $event, $source): array {
            $current = $record === null ? new $modelClass : $modelClass::query()->lockForUpdate()->findOrFail($record->id);
            $before = $record === null ? [] : $current->only(['id', 'name', 'status', 'version']);
            if ($record !== null && $current->version !== (int) $data['version']) {
                throw new LotsConflict('La versión del catálogo cambió. Actualiza los datos.');
            }
            if (isset($data['name'])) {
                if ($data['name'] === '') {
                    throw new LotsConflict('El nombre no puede estar vacío.');
                }
                $current->name = $data['name'];
            }
            $current->status = $data['status'] ?? ($record === null ? 'active' : $current->status);
            $current->version = $record === null ? 1 : $current->version + 1;
            $current->save();
            $after = $current->only(['id', 'name', 'status', 'version']);
            $this->history->audit($current, $actor, $event.($record === null ? '_created' : '_updated'), 'Catálogo de lotes actualizado', $operationId, $before, $after, source: $source);

            return ['catalog' => $after];
        });
    }
}
