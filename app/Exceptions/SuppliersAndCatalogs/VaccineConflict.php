<?php

namespace App\Exceptions\SuppliersAndCatalogs;

use DomainException;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VaccineConflict extends DomainException implements ShouldntReport
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'title' => 'Conflicto de vacuna',
            'status' => 409,
            'detail' => $this->getMessage(),
            'message' => $this->getMessage(),
        ], 409)->header('Content-Type', 'application/problem+json');
    }
}
