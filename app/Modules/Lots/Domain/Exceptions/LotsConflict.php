<?php

namespace App\Modules\Lots\Domain\Exceptions;

use DomainException;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LotsConflict extends DomainException implements ShouldntReport
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'type' => 'https://httpstatuses.com/409',
            'title' => 'Conflicto de lotes',
            'status' => 409,
            'detail' => $this->getMessage(),
            'message' => $this->getMessage(),
        ], 409)->header('Content-Type', 'application/problem+json');
    }
}
