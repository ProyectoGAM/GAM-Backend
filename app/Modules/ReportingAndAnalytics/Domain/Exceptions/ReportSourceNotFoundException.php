<?php

namespace App\Modules\ReportingAndAnalytics\Domain\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class ReportSourceNotFoundException extends RuntimeException implements ShouldntReport
{
    public function __construct(string $sourceKey)
    {
        parent::__construct("La fuente de reporte {$sourceKey} no existe.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'tipo' => 'https://httpstatuses.com/404',
            'title' => 'Fuente de reporte no encontrada',
            'estado' => 404,
            'detail' => $this->getMessage(),
        ], 404, ['Content-Type' => 'application/problem+json']);
    }
}
