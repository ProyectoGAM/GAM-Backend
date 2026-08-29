<?php

namespace App\Modules\FarmStructure\Domain\Exceptions;

use DomainException;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class FarmStructureConflict extends DomainException implements ShouldntReport
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
        ], Response::HTTP_CONFLICT);
    }
}
