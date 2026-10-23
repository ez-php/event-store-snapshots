<?php

declare(strict_types=1);

namespace Tests;

use Closure;
use DateTimeImmutable;
use EzPhp\EventStore\PdoEventStore;
use EzPhp\EventStore\StoredEvent;
use EzPhp\EventStoreSnapshots\EveryNEventsPolicy;
use EzPhp\EventStoreSnapshots\InMemorySnapshotStore;
use EzPhp\EventStoreSnapshots\LoadedState;
use EzPhp\EventStoreSnapshots\Snapshot;
use EzPhp\EventStoreSnapshots\SnapshottingLoader;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\SnapshotTestEvent;

/**
 * Class SnapshottingLoaderTest
 *
 * Runs against a real PdoEventStore on SQLite and the in-memory snapshot store.
 *
 * @package Tests
 */
#[CoversClass(SnapshottingLoader::class)]
#[CoversClass(LoadedState::class)]
#[UsesClass(EveryNEventsPolicy::class)]
#[UsesClass(InMemorySnapshotStore::class)]
#[UsesClass(Snapshot::class)]
final class SnapshottingLoaderTest extends TestCase
{
    private PdoEventStore $events;

    private InMemorySnapshotStore $snapshots;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->events = new PdoEventStore($pdo);
        $this->snapshots = new InMemorySnapshotStore();
    }

    /**
     * @return Closure(array<string, mixed>, StoredEvent): array<string, mixed>
     */
    private function balanceReducer(): Closure
    {
        return static function (array $state, StoredEvent $event): array {
            $balance = is_int($state['balance'] ?? null) ? $state['balance'] : 0;
            $amount = is_int($event->payload['amount'] ?? null) ? $event->payload['amount'] : 0;

            return ['balance' => match ($event->eventType) {
                'deposited' => $balance + $amount,
                'withdrawn' => $balance - $amount,
                default => $balance,
            }];
        };
    }

    private function deposit(int $times, int $amount = 10): void
    {
        $events = [];

        for ($i = 0; $i < $times; $i++) {
            $events[] = new SnapshotTestEvent('deposited', ['amount' => $amount]);
        }

        $this->events->append('account-1', $events);
    }

    private function loader(int $every = 3, string $stateVersion = '1'): SnapshottingLoader
    {
        return new SnapshottingLoader($this->events, $this->snapshots, new EveryNEventsPolicy($every), $stateVersion);
    }

    public function testEmptyStreamReturnsInitialStateWithoutSnapshot(): void
    {
        $loaded = $this->loader()->load('account-1', ['balance' => 0], $this->balanceReducer());

        $this->assertSame(['balance' => 0], $loaded->state);
        $this->assertSame(0, $loaded->version);
        $this->assertSame(0, $loaded->replayedEvents);
        $this->assertFalse($loaded->fromSnapshot);
        $this->assertNull($this->snapshots->latest('account-1'));
    }

    public function testBelowThresholdFoldsWithoutSnapshot(): void
    {
        $this->deposit(2);

        $loaded = $this->loader()->load('account-1', ['balance' => 0], $this->balanceReducer());

        $this->assertSame(['balance' => 20], $loaded->state);
        $this->assertSame(2, $loaded->version);
        $this->assertNull($this->snapshots->latest('account-1'));
    }

    public function testReachingThresholdSavesSnapshotAndNextLoadReplaysOnlyNewEvents(): void
    {
        $this->deposit(3);
        $loader = $this->loader();

        $first = $loader->load('account-1', ['balance' => 0], $this->balanceReducer());

        $snapshot = $this->snapshots->latest('account-1');
        $this->assertNotNull($snapshot);
        $this->assertSame(3, $snapshot->version);
        $this->assertSame(['balance' => 30], $snapshot->state);
        $this->assertSame(3, $first->replayedEvents);

        $this->events->append('account-1', [new SnapshotTestEvent('withdrawn', ['amount' => 5])]);

        $second = $loader->load('account-1', ['balance' => 0], $this->balanceReducer());

        $this->assertSame(['balance' => 25], $second->state);
        $this->assertSame(4, $second->version);
        $this->assertSame(1, $second->replayedEvents);
        $this->assertTrue($second->fromSnapshot);
        $this->assertSame(3, $this->snapshots->latest('account-1')?->version);
    }

    public function testSnapshotIsUsedEvenWhenNoNewEventsExist(): void
    {
        $this->deposit(3);
        $loader = $this->loader();
        $loader->load('account-1', ['balance' => 0], $this->balanceReducer());

        $loaded = $loader->load('account-1', ['balance' => 0], $this->balanceReducer());

        $this->assertTrue($loaded->fromSnapshot);
        $this->assertSame(0, $loaded->replayedEvents);
        $this->assertSame(['balance' => 30], $loaded->state);
        $this->assertSame(3, $loaded->version);
    }

    public function testSnapshotWithOtherStateVersionIsIgnoredAndReplaced(): void
    {
        $this->deposit(3);
        $this->snapshots->save(new Snapshot('account-1', 2, ['legacy' => true], 'old', new DateTimeImmutable()));

        $loaded = $this->loader(stateVersion: '2')->load('account-1', ['balance' => 0], $this->balanceReducer());

        $this->assertFalse($loaded->fromSnapshot);
        $this->assertSame(['balance' => 30], $loaded->state);
        $this->assertSame(3, $loaded->replayedEvents);
        $this->assertSame('2', $this->snapshots->latest('account-1')?->stateVersion);
    }

    public function testTakeSnapshotsRegardlessOfPolicy(): void
    {
        $this->deposit(1);

        $loaded = $this->loader(every: 100)->take('account-1', ['balance' => 0], $this->balanceReducer());

        $this->assertSame(1, $loaded->version);
        $this->assertSame(['balance' => 10], $this->snapshots->latest('account-1')?->state);
    }

    public function testTakeSkipsEmptyStream(): void
    {
        $this->loader()->take('account-1', ['balance' => 0], $this->balanceReducer());

        $this->assertNull($this->snapshots->latest('account-1'));
    }
}
