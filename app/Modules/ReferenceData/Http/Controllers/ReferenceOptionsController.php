<?php

namespace App\Modules\ReferenceData\Http\Controllers;

use App\Models\User;
use App\Modules\ReferenceData\Application\Queries\ListReferenceOptionsQuery;
use App\Modules\ReferenceData\Http\Requests\ListReferenceOptionsRequest;
use App\Modules\ReferenceData\Http\Resources\ReferenceOptionsResource;

final readonly class ReferenceOptionsController
{
    public function __invoke(
        ListReferenceOptionsRequest $request,
        ListReferenceOptionsQuery $query,
    ): ReferenceOptionsResource {
        /** @var User $actor */
        $actor = $request->user();

        return new ReferenceOptionsResource($query->execute($actor));
    }
}
