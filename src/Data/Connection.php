<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Data;

use Carbon\CarbonImmutable;

/** The connection as shown to people and APIs. Never carries a token. */
final readonly class Connection
{
    /** @param  list<string>  $scopes */
    public function __construct(
        public bool $connected,
        public ?string $status = null,
        public ?string $name = null,
        public ?string $username = null,
        public ?string $picture = null,
        public array $scopes = [],
        public ?CarbonImmutable $connectedAt = null,
    ) {}

    public static function disconnected(): self
    {
        return new self(connected: false);
    }

    public static function fromStored(StoredConnection $stored, string $status): self
    {
        return new self(
            connected: true,
            status: $status,
            name: $stored->name,
            username: $stored->username,
            picture: $stored->picture,
            scopes: $stored->scopes,
            connectedAt: $stored->connectedAt,
        );
    }

    public function withStatus(string $status): self
    {
        return new self($this->connected, $status, $this->name, $this->username, $this->picture,
            $this->scopes, $this->connectedAt);
    }

    /**
     * Plain data for the cache. Objects are never cached: Laravel 13 stores
     * refuse to unserialize classes by default (cache.serializable_classes).
     *
     * @return array{connected: bool, status: ?string, name: ?string, username: ?string, picture: ?string, scopes: list<string>, connected_at: ?string}
     */
    public function toCache(): array
    {
        return [
            'connected' => $this->connected,
            'status' => $this->status,
            'name' => $this->name,
            'username' => $this->username,
            'picture' => $this->picture,
            'scopes' => $this->scopes,
            'connected_at' => $this->connectedAt?->toIso8601String(),
        ];
    }

    /** @param  array<mixed>  $data  What toCache() returned. */
    public static function fromCache(array $data): self
    {
        $string = fn (string $key): ?string => is_string($data[$key] ?? null) ? $data[$key] : null;
        $scopes = is_array($data['scopes'] ?? null) ? $data['scopes'] : [];

        return new self(
            connected: ($data['connected'] ?? false) === true,
            status: $string('status'),
            name: $string('name'),
            username: $string('username'),
            picture: $string('picture'),
            scopes: array_values(array_filter($scopes, 'is_string')),
            connectedAt: ($value = $string('connected_at')) !== null ? CarbonImmutable::parse($value) : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->toCache();
    }
}
