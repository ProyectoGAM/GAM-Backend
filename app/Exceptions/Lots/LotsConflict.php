<?php

namespace App\Exceptions\Lots;

use DomainException;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class LotsConflict extends DomainException implements ShouldntReport
{
    public readonly ?string $errorCode;

    /** @param array<string, mixed> $meta */
    public function __construct(string $message, ?string $code = null, public readonly array $meta = [], ?Throwable $previous = null)
    {
        $this->errorCode = $code;
        parent::__construct($message, 0, $previous);
    }

    public function render(Request $request): JsonResponse
    {
        $body = [
            'type' => 'https://httpstatuses.com/409',
            'title' => 'Conflicto de lotes',
            'status' => 409,
            'detail' => $this->getMessage(),
            'message' => $this->getMessage(),
        ];
        if ($this->errorCode !== null) {
            $body['code'] = $this->errorCode;
        }
        if ($this->meta !== []) {
            $body['meta'] = $this->meta;
        }

        return response()->json($body, 409)->header('Content-Type', 'application/problem+json');
    }
}
