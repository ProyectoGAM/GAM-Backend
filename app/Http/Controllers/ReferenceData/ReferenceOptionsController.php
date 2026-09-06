<?php

namespace App\Http\Controllers\ReferenceData;

use App\Http\Requests\ReferenceData\ListReferenceOptionsRequest;
use App\Http\Resources\ReferenceData\ReferenceOptionsResource;
use App\Models\User;
use App\Queries\ReferenceData\ListReferenceOptionsQuery;

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
