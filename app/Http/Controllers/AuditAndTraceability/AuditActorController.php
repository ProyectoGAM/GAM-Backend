<?php

namespace App\Http\Controllers\AuditAndTraceability;

use App\Http\Requests\AuditAndTraceability\ListAuditActorsRequest;
use App\Http\Resources\AuditAndTraceability\AuditActorResource;
use App\Models\User;
use App\Queries\AuditAndTraceability\ListAuditActorsQuery;
use App\Services\AuditAndTraceability\AuditActorPresenter;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AuditActorController
{
    public function index(ListAuditActorsRequest $request, ListAuditActorsQuery $query, AuditActorPresenter $actors): AnonymousResourceCollection
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $entries = $query->execute($request->validated());
        foreach ($entries->getCollection() as $entry) {
            $entry->setRelation('auditActorPresentation', $actors->present($entry, $viewer));
        }

        return AuditActorResource::collection($entries);
    }
}
