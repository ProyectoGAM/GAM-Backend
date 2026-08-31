<?php

namespace App\Modules\ReportingAndAnalytics\Domain\Exceptions;

use DomainException;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReportQueryValidationException extends DomainException implements ShouldntReport
{
    /** @param array<string, list<string>> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('La consulta del reporte no es válida.');
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'tipo' => 'https://httpstatuses.com/422',
            'title' => 'Consulta de reporte inválida',
            'estado' => Response::HTTP_UNPROCESSABLE_ENTITY,
            'detail' => $this->getMessage(),
            'errors' => $this->errors,
        ], Response::HTTP_UNPROCESSABLE_ENTITY)->header('Content-Type', 'application/problem+json');
    }
}
