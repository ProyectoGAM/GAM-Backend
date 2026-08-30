<?php

namespace App\Modules\ReportingAndAnalytics\Domain\Exceptions;

use DomainException;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReportConflict extends DomainException implements ShouldntReport
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'tipo' => 'https://httpstatuses.com/409',
            'title' => 'Conflicto de reporte',
            'estado' => Response::HTTP_CONFLICT,
            'detail' => $this->getMessage(),
        ], Response::HTTP_CONFLICT)->header('Content-Type', 'application/problem+json');
    }
}
