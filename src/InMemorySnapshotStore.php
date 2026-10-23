<?php

declare(strict_types=1);

namespace EzPhp\EventStoreSnapshots;

/**
 * Class InMemorySnapshotStore
 *
 * Process-local SnapshotStoreInterface for tests and short-lived workers;
 * snapshots are lost when the process ends.
 *
 * @package EzPhp\EventStoreSnapshots
 */
final class InMemorySnapshotStore implements SnapshotStoreInterface
{
    /**
     * @var array<string, Snapshot>
     */
    private array $snapshots = [];

    /**
     * {@inheritDoc}
     */
    public function save(Snapshot $snapshot): void
    {
        $current = $this->snapshots[$snapshot->streamId] ?? null;

        if ($current !== null && $current->version >= $snapshot->version) {
            return;
        }

        $this->snapshots[$snapshot->streamId] = $snapshot;
    }

    /**
     * {@inheritDoc}
     */
    public function latest(string $streamId): ?Snapshot
    {
        return $this->snapshots[$streamId] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $streamId): void
    {
        unset($this->snapshots[$streamId]);
    }
}
