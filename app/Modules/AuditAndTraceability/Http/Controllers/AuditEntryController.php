<?php

namespace App\Modules\AuditAndTraceability\Http\Controllers;

use App\Modules\AuditAndTraceability\Application\Queries\ListAuditEntriesQuery;
use App\Modules\AuditAndTraceability\Http\Requests\ListAuditEntriesRequest;
use App\Modules\AuditAndTraceability\Http\Resources\AuditEntryResource;
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
