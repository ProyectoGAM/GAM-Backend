<?php

namespace App\Exceptions\IdentityAndAccess;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class IdentityException extends HttpException
{
    public function __construct(
        int $statusCode,
        public readonly string $errorCode,
        string $message,
        array $headers = [],
    ) {
        parent::__construct($statusCode, $message, null, $headers);
    }
}
