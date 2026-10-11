<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Support;

use Siberfx\XAutopost\Data\Connection;

final class ConnectionPresenter
{
    public function __construct(public readonly Connection $connection) {}

    /** @return array{label: string, tone: 'success'|'warning'|'danger'|'neutral'} */
    public function badge(): array
    {
        return match ($this->connection->status) {
            'expired' => ['label' => 'Expired', 'tone' => 'danger'],
            'revoked' => ['label' => 'Revoked on X', 'tone' => 'danger'],
            'unknown' => ['label' => 'Status unknown', 'tone' => 'neutral'],
            default => ['label' => 'Connected', 'tone' => 'success'],
        };
    }

    public function needsReconnect(): bool
    {
        return in_array($this->badge()['tone'], ['warning', 'danger'], true);
    }

    public function displayName(): string
    {
        return $this->connection->name ?? 'X account';
    }

    public function handle(): ?string
    {
        return $this->connection->username !== null ? '@'.$this->connection->username : null;
    }

    public function initials(): string
    {
        $parts = array_slice(array_values(array_filter(preg_split('/\s+/u', trim($this->displayName())) ?: [])), 0, 2);

        return mb_strtoupper(implode('', array_map(fn (string $part) => mb_substr($part, 0, 1), $parts)));
    }

    public function connectedOn(): ?string
    {
        return $this->connection->connectedAt?->format('j M Y');
    }
}
