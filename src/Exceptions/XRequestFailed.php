<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

final class XRequestFailed extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response): self
    {
        $reason = $response->json('message')
            ?? $response->json('error_description')
            ?? $response->json('error');

        $reason = is_string($reason) && $reason !== '' ? $reason : 'HTTP '.$response->status();

        return new self('X rejected the request: '.$reason, $response->status());
    }

    public function isUnauthorized(): bool
    {
        return $this->status === 401;
    }

    /** A 4xx other than 429: sending the same request again cannot succeed. */
    public function isPermanent(): bool
    {
        return $this->status >= 400 && $this->status < 500 && $this->status !== 429;
    }
}
