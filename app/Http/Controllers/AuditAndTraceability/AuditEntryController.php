<?php

namespace App\Http\Controllers\AuditAndTraceability;

use App\Http\Requests\AuditAndTraceability\ListAuditEntriesRequest;
use App\Http\Requests\AuditAndTraceability\ShowAuditEntryRequest;
use App\Http\Resources\AuditAndTraceability\AuditEntryDetailResource;
use App\Http\Resources\AuditAndTraceability\AuditEntryResource;
use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\User;
use App\Queries\AuditAndTraceability\ListAuditEntriesQuery;
use App\Services\AuditAndTraceability\AuditActorPresenter;
use App\Services\AuditAndTraceability\AuditPresentation;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AuditEntryController
{
    public function index(
        ListAuditEntriesRequest $request,
        ListAuditEntriesQuery $listAuditEntries,
        AuditActorPresenter $actors,
        AuditPresentation $presentation,
    ): AnonymousResourceCollection {
        /** @var User $viewer */
        $viewer = $request->user();
        $entries = $listAuditEntries->execute($request->validated());
        $actors->prepare($entries->getCollection());
        foreach ($entries->getCollection() as $entry) {
            $entry->setRelation('auditActorPresentation', $actors->present($entry, $viewer));
            $entry->setRelation('auditPresentation', $presentation->summary($entry));
        }

        return AuditEntryResource::collection($entries);
    }

    public function show(ShowAuditEntryRequest $request, AuditEntry $auditEntry, AuditActorPresenter $actors, AuditPresentation $presentation): AuditEntryDetailResource
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $actors->prepare(collect([$auditEntry]));
        $auditEntry->setRelation('auditActorPresentation', $actors->present($auditEntry, $viewer));
        $auditEntry->setRelation('auditPresentation', $presentation->detail($auditEntry, $viewer));

        return new AuditEntryDetailResource($auditEntry);
    }
}
