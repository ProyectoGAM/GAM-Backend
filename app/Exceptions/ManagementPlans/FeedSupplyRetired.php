<?php

namespace App\Exceptions\ManagementPlans;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

final class FeedSupplyRetired extends GoneHttpException
{
    public function __construct()
    {
        parent::__construct('Las nuevas cargas de suministros de alimento están retiradas. Utiliza la alimentación por recetas y saldos por unidad productiva.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'type' => 'https://httpstatuses.com/410',
            'title' => 'Registro de suministros retirado',
            'status' => 410,
            'detail' => $this->getMessage(),
            'message' => $this->getMessage(),
        ], 410)->header('Content-Type', 'application/problem+json');
    }
}
