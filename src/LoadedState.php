<?php

declare(strict_types=1);

namespace EzPhp\EventStoreSnapshots;

/**
 * Class LoadedState
 *
 * The result of SnapshottingLoader::load(): the folded state and the stream
 * version it reflects — pass `$version` as `expectedVersion` to the next
 * EventStoreInterface::append() for optimistic concurrency.
 *
 * @package EzPhp\EventStoreSnapshots
 */
final readonly class LoadedState
{
    /**
     * @param array<string, mixed> $state           Folded state.
     * @param int                  $version         Stream version the state includes (0 for an empty stream).
     * @param int                  $replayedEvents  Events replayed on top of the snapshot used (or from the start).
     * @param bool                 $fromSnapshot    Whether a stored snapshot was the starting point.
     */
    public function __construct(
        public array $state,
        public int $version,
        public int $replayedEvents,
        public bool $fromSnapshot,
    ) {
    }
}
