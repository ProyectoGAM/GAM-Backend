<?php

namespace App\Services\CustomersAndAccounts;

use App\Exceptions\CustomersAndAccounts\CustomerAccountConflict;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\CustomersAndAccounts\CustomerOperation;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CustomerOperationRunner
{
    /**
     * Conserva incluso operaciones sin movimientos: altas, ediciones y entregas sin cargo.
     *
     * @param  array<string, mixed>  $payload
     * @param  Closure(CustomerOperation, User): array<string, mixed>  $mutation
     */
    public function run(string $kind, string $key, array $payload, User $actor, string $ability, Closure $mutation): CustomerOperation
    {
        if (! Str::isUuid($key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'El encabezado Idempotency-Key debe ser un UUID válido.']);
        }
        $key = Str::lower($key);
        $hash = hash('sha256', json_encode([$kind, $this->canonical($payload)], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($kind, $key, $hash, $actor, $ability, $mutation): CustomerOperation {
            $currentActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();
            if ($currentActor === null) {
                throw new AuthorizationException;
            }
            if (str_starts_with($ability, 'delivery.')) {
                if (! $currentActor->can($ability)) {
                    throw new AuthorizationException;
                }
            } else {
                Gate::forUser($currentActor)->authorize($ability, Customer::class);
            }
            $existing = CustomerOperation::query()->where('created_by', $currentActor->id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ($existing->request_hash !== $hash) {
                    throw new CustomerAccountConflict('La clave de idempotencia ya fue utilizada con otros datos.');
                }

                return $existing;
            }
            $operation = new CustomerOperation;
            $operation->forceFill(['operation_id' => (string) Str::uuid(), 'created_by' => $currentActor->id,
                'idempotency_key' => $key, 'kind' => $kind, 'request_hash' => $hash, 'result' => []])->save();
            $operation->forceFill(['result' => $mutation($operation, $currentActor)])->save();

            return $operation;
        }, 3);
    }

    /** @param array<array-key, mixed> $value */
    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }
}
