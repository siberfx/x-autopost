<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Data;

use Carbon\CarbonImmutable;

/**
 * The connection including its tokens. Passed only between the token store
 * and the manager; never serialised to a response, log or event.
 */
final readonly class StoredConnection
{
    /** @param  list<string>  $scopes */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken = null,
        public ?string $username = null,
        public ?string $name = null,
        public ?string $picture = null,
        public array $scopes = [],
        public ?CarbonImmutable $connectedAt = null,
    ) {}

    /** The same account with a new pair of tokens. X rotates the refresh token on every refresh. */
    public function withTokens(string $accessToken, ?string $refreshToken): self
    {
        return new self($accessToken, $refreshToken ?? $this->refreshToken, $this->username, $this->name,
            $this->picture, $this->scopes, $this->connectedAt);
    }
}
