<?php

namespace App\Exceptions\CustomersAndAccounts;

use DomainException;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CustomerAccountConflict extends DomainException implements ShouldntReport
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['type' => 'https://httpstatuses.com/409', 'title' => 'Conflicto de cuenta corriente',
            'status' => 409, 'detail' => $this->getMessage(), 'message' => $this->getMessage()], 409)
            ->header('Content-Type', 'application/problem+json');
    }
}
