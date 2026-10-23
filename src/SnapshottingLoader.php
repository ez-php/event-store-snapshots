<?php

declare(strict_types=1);

namespace EzPhp\EventStoreSnapshots;

use Closure;
use DateTimeImmutable;
use EzPhp\EventStore\EventStoreInterface;
use EzPhp\EventStore\StoredEvent;

/**
 * Class SnapshottingLoader
 *
 * Folds a stream into state, starting from its latest snapshot and replaying
 * only the events after it (`EventStoreInterface::load($streamId, $snapshotVersion)`).
 * When the policy says so, the new state is saved as the stream's snapshot.
 *
 *   $loader = new SnapshottingLoader($eventStore, $snapshots, new EveryNEventsPolicy(100));
 *   $account = $loader->load('account-42', ['balance' => 0], fn (array $state, StoredEvent $e): array => match ($e->eventType) {
 *       'deposited' => ['balance' => $state['balance'] + $e->payload['amount']],
 *       default     => $state,
 *   });
 *
 * State is a plain array and the reducer a pure function, matching
 * ez-php/event-store's "payloads stay arrays" design — no aggregate base class.
 *
 * @package EzPhp\EventStoreSnapshots
 */
final readonly class SnapshottingLoader
{
    /**
     * @param EventStoreInterface     $events
     * @param SnapshotStoreInterface  $snapshots
     * @param SnapshotPolicyInterface $policy
     * @param string                  $stateVersion Shape version of the state the reducer produces. Bump it when the
     *                                              reducer changes incompatibly: older snapshots are then ignored
     *                                              and replaced on the next qualifying load.
     */
    public function __construct(
        private EventStoreInterface $events,
        private SnapshotStoreInterface $snapshots,
        private SnapshotPolicyInterface $policy,
        private string $stateVersion = '1',
    ) {
    }

    /**
     * Load the stream's current state, saving a snapshot when the policy asks for one.
     *
     * @param string                                                          $streamId
     * @param array<string, mixed>                                            $initialState State before the first event.
     * @param Closure(array<string, mixed>, StoredEvent): array<string, mixed> $reducer      Applies one event to the state.
     *
     * @throws SnapshotException When the snapshot store fails.
     *
     * @return LoadedState
     */
    public function load(string $streamId, array $initialState, Closure $reducer): LoadedState
    {
        $loaded = $this->replay($streamId, $initialState, $reducer);

        if ($loaded->replayedEvents > 0 && $this->policy->shouldSnapshot($loaded->replayedEvents, $loaded->version)) {
            $this->save($streamId, $loaded);
        }

        return $loaded;
    }

    /**
     * Load the stream's current state and save it as a snapshot regardless of
     * the policy (e.g. from a maintenance command). An empty stream is not
     * snapshotted.
     *
     * @param string                                                          $streamId
     * @param array<string, mixed>                                            $initialState
     * @param Closure(array<string, mixed>, StoredEvent): array<string, mixed> $reducer
     *
     * @throws SnapshotException When the snapshot store fails.
     *
     * @return LoadedState
     */
    public function take(string $streamId, array $initialState, Closure $reducer): LoadedState
    {
        $loaded = $this->replay($streamId, $initialState, $reducer);

        if ($loaded->version > 0) {
            $this->save($streamId, $loaded);
        }

        return $loaded;
    }

    /**
     * @param string                                                          $streamId
     * @param array<string, mixed>                                            $initialState
     * @param Closure(array<string, mixed>, StoredEvent): array<string, mixed> $reducer
     *
     * @return LoadedState
     */
    private function replay(string $streamId, array $initialState, Closure $reducer): LoadedState
    {
        $snapshot = $this->snapshots->latest($streamId);
        $usable = $snapshot !== null && $snapshot->stateVersion === $this->stateVersion;

        $state = $usable ? $snapshot->state : $initialState;
        $version = $usable ? $snapshot->version : 0;
        $replayed = 0;

        foreach ($this->events->load($streamId, $version) as $event) {
            $state = $reducer($state, $event);
            $version = $event->version;
            $replayed++;
        }

        return new LoadedState($state, $version, $replayed, $usable);
    }

    /**
     * @param string      $streamId
     * @param LoadedState $loaded
     *
     * @return void
     */
    private function save(string $streamId, LoadedState $loaded): void
    {
        $this->snapshots->save(new Snapshot($streamId, $loaded->version, $loaded->state, $this->stateVersion, new DateTimeImmutable()));
    }
}
