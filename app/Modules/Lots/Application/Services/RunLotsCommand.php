<?php

namespace App\Modules\Lots\Application\Services;

use App\Models\Lots\FlockOperation;
use App\Models\User;
use App\Modules\Lots\Domain\Exceptions\LotsConflict;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class RunLotsCommand
{
    /**
     * Serializa por actor y comprueba reintentos antes de consultar estado mutable.
     *
     * @param  array<string, mixed>  $payload
     * @param  Closure(string): array<string, mixed>  $operation
     */
    public function execute(User $actor, string $permission, string $command, string $key, array $payload, Closure $operation): FlockOperation
    {
        if (! Str::isUuid($key)) {
            throw new LotsConflict('La clave de idempotencia debe ser un UUID válido.');
        }
        $hash = hash('sha256', json_encode([$command, $this->canonical($payload)], JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($actor, $permission, $command, $key, $hash, $operation): FlockOperation {
                $currentActor = User::query()->whereKey($actor->id)->lockForUpdate()->first();
                if ($currentActor === null) {
                    throw new AuthorizationException;
                }
                LotsAuthorization::ensure($currentActor, $permission);
                $existing = FlockOperation::query()->where('created_by', $actor->id)->where('idempotency_key', $key)->first();
                if ($existing !== null) {
                    if ($existing->request_hash !== $hash) {
                        throw new LotsConflict('La clave de idempotencia ya fue utilizada con otros datos.');
                    }

                    return $existing;
                }
                $operationId = (string) Str::uuid();
                $result = $operation($operationId);
                $record = new FlockOperation;
                $record->forceFill([
                    'operation_id' => $operationId,
                    'created_by' => $actor->id,
                    'idempotency_key' => $key,
                    'command' => $command,
                    'request_hash' => $hash,
                    'result' => $result,
                ])->save();

                return $record;
            }, 3);
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? '') === '23505') {
                throw new LotsConflict('El identificador o nombre ya está registrado.', previous: $exception);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }
}
