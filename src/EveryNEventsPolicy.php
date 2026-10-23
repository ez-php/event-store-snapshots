<?php

declare(strict_types=1);

namespace EzPhp\EventStoreSnapshots;

use InvalidArgumentException;

/**
 * Class EveryNEventsPolicy
 *
 * Snapshot once at least N events had to be replayed on top of the previous
 * snapshot — so a load never replays more than about N events again.
 *
 * @package EzPhp\EventStoreSnapshots
 */
final readonly class EveryNEventsPolicy implements SnapshotPolicyInterface
{
    /**
     * @param int $every Minimum number of replayed events that triggers a snapshot (>= 1).
     *
     * @throws InvalidArgumentException When `$every` is below 1.
     */
    public function __construct(private int $every)
    {
        if ($every < 1) {
            throw new InvalidArgumentException('EveryNEventsPolicy needs a threshold of at least 1.');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function shouldSnapshot(int $eventsSinceSnapshot, int $version): bool
    {
        return $eventsSinceSnapshot >= $this->every;
    }
}
