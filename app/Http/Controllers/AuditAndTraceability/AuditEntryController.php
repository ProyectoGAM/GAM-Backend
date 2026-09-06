<?php

namespace App\Http\Controllers\AuditAndTraceability;

use App\Http\Requests\AuditAndTraceability\ListAuditEntriesRequest;
use App\Http\Resources\AuditAndTraceability\AuditEntryResource;
use App\Queries\AuditAndTraceability\ListAuditEntriesQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AuditEntryController
{
    public function index(
        ListAuditEntriesRequest $request,
        ListAuditEntriesQuery $listAuditEntries,
    ): AnonymousResourceCollection {
        return AuditEntryResource::collection(
            $listAuditEntries->execute($request->validated()),
        );
    }
}
