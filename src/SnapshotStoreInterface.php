<?php

declare(strict_types=1);

namespace EzPhp\EventStoreSnapshots;

/**
 * Interface SnapshotStoreInterface
 *
 * Keeps the latest snapshot per stream. Older snapshots are replaced, never
 * accumulated.
 *
 * @package EzPhp\EventStoreSnapshots
 */
interface SnapshotStoreInterface
{
    /**
     * Store a snapshot unless the stream already has one at the same or a
     * higher version (a concurrent loader may have saved a newer one first).
     *
     * @param Snapshot $snapshot
     *
     * @throws SnapshotException When the underlying storage fails.
     *
     * @return void
     */
    public function save(Snapshot $snapshot): void;

    /**
     * The stream's latest snapshot, or null when it has none.
     *
     * @param string $streamId
     *
     * @throws SnapshotException When the underlying storage fails.
     *
     * @return Snapshot|null
     */
    public function latest(string $streamId): ?Snapshot;

    /**
     * Remove the stream's snapshot (e.g. after the reducer changed in a way the
     * stored state no longer reflects).
     *
     * @param string $streamId
     *
     * @throws SnapshotException When the underlying storage fails.
     *
     * @return void
     */
    public function delete(string $streamId): void;
}
