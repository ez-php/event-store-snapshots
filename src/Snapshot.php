<?php

declare(strict_types=1);

namespace EzPhp\EventStoreSnapshots;

use DateTimeImmutable;

/**
 * Class Snapshot
 *
 * The folded state of one stream up to and including `$version`, so a load
 * only has to replay the events after it.
 *
 * @package EzPhp\EventStoreSnapshots
 */
final readonly class Snapshot
{
    /**
     * @param string               $streamId
     * @param int                  $version      Stream version the state includes (last folded event).
     * @param array<string, mixed> $state        Folded state.
     * @param string               $stateVersion Shape version of `$state`; a loader configured with a
     *                                           different one ignores the snapshot and replays from scratch.
     * @param DateTimeImmutable    $takenAt
     */
    public function __construct(
        public string $streamId,
        public int $version,
        public array $state,
        public string $stateVersion,
        public DateTimeImmutable $takenAt,
    ) {
    }
}
