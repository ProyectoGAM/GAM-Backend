<?php

namespace App\Modules\Lots\Application\Actions;

use App\Models\Lots\Breed;
use App\Models\Lots\FlockOperation;
use App\Models\User;
use App\Modules\Lots\Application\Services\SaveLotsCatalog;

final readonly class SaveBreedAction
{
    public function __construct(private SaveLotsCatalog $catalogs) {}

    /** @param array<string, mixed> $data */
    public function execute(?Breed $record, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->catalogs->execute(Breed::class, $record, $data, $actor, 'breeds.manage', 'breed', $source);
    }
}
