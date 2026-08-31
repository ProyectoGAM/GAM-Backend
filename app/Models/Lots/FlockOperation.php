<?php

namespace App\Models\Lots;

use Database\Factories\Lots\FlockOperationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $operation_id
 * @property string $request_hash
 * @property array<string, mixed> $result
 */
class FlockOperation extends Model
{
    /** Conserva el instante aunque PostgreSQL use una zona horaria distinta de UTC. */
    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @use HasFactory<FlockOperationFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['result' => 'array'];
    }
}
