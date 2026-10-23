<?php

declare(strict_types=1);

namespace EzPhp\EventStoreSnapshots;

/**
 * Interface SnapshotPolicyInterface
 *
 * Decides, after a load, whether SnapshottingLoader writes a new snapshot.
 *
 * @package EzPhp\EventStoreSnapshots
 */
interface SnapshotPolicyInterface
{
    /**
     * @param int $eventsSinceSnapshot Events replayed on top of the previous snapshot (or from the start).
     * @param int $version             Stream version after the replay.
     *
     * @return bool
     */
    public function shouldSnapshot(int $eventsSinceSnapshot, int $version): bool;
}
