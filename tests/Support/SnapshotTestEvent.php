<?php

declare(strict_types=1);

namespace Tests\Support;

use EzPhp\EventStore\DomainEvent;

/**
 * Minimal DomainEvent fixture for appending to a real PdoEventStore.
 *
 * @package Tests\Support
 */
final readonly class SnapshotTestEvent implements DomainEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private string $type,
        private array $payload = [],
    ) {
    }

    public function eventType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}
