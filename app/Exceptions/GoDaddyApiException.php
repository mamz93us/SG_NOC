<?php

namespace App\Exceptions;

class GoDaddyApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly array $errors = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public static function fromResponse(int $status, string $body): static
    {
        $parsed = json_decode($body, true) ?? [];
        $msg    = $parsed['message'] ?? "GoDaddy API returned HTTP {$status}";
        $errors = $parsed['fields'] ?? [];

        return new static($msg, $status, $errors);
    }
}
