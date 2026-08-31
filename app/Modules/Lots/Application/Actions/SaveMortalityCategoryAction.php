<?php

namespace App\Modules\Lots\Application\Actions;

use App\Models\Lots\FlockOperation;
use App\Models\Lots\MortalityCategory;
use App\Models\User;
use App\Modules\Lots\Application\Services\SaveLotsCatalog;

final readonly class SaveMortalityCategoryAction
{
    public function __construct(private SaveLotsCatalog $catalogs) {}

    /** @param array<string, mixed> $data */
    public function execute(?MortalityCategory $record, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->catalogs->execute(MortalityCategory::class, $record, $data, $actor, 'mortality-categories.manage', 'mortality_category', $source);
    }
}
