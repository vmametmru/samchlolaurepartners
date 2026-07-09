<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $error,
        string $message
    ) {
        parent::__construct($message, $statusCode);
    }
}
