<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use EzPhp\EventStoreSnapshots\InMemorySnapshotStore;
use EzPhp\EventStoreSnapshots\PdoSnapshotStore;
use EzPhp\EventStoreSnapshots\Snapshot;
use EzPhp\EventStoreSnapshots\SnapshotException;
use EzPhp\EventStoreSnapshots\SnapshotStoreInterface;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Class SnapshotStoresTest
 *
 * The same contract checks against both stores (PdoSnapshotStore on SQLite),
 * plus PDO-specific failure handling.
 *
 * @package Tests
 */
#[CoversClass(InMemorySnapshotStore::class)]
#[CoversClass(PdoSnapshotStore::class)]
#[CoversClass(Snapshot::class)]
#[UsesClass(SnapshotException::class)]
final class SnapshotStoresTest extends TestCase
{
    /**
     * @return array<string, array{\Closure(): SnapshotStoreInterface}>
     */
    public static function storeProvider(): array
    {
        return [
            'in-memory' => [static fn (): SnapshotStoreInterface => new InMemorySnapshotStore()],
            'pdo (sqlite)' => [static function (): SnapshotStoreInterface {
                $pdo = new PDO('sqlite::memory:');
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                return new PdoSnapshotStore($pdo);
            }],
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function snapshot(string $stream, int $version, array $state = ['n' => 1]): Snapshot
    {
        return new Snapshot($stream, $version, $state, '1', new DateTimeImmutable('2026-10-23 12:00:00.123456'));
    }

    /**
     * @param \Closure(): SnapshotStoreInterface $make
     */
    #[DataProvider('storeProvider')]
    public function testRoundTripPerStream(\Closure $make): void
    {
        $store = $make();
        $store->save($this->snapshot('a', 3, ['balance' => 30, 'tags' => ['x'], 'ratio' => 1.0]));
        $store->save($this->snapshot('b', 1));

        $a = $store->latest('a');
        $this->assertNotNull($a);
        $this->assertSame(3, $a->version);
        $this->assertSame(['balance' => 30, 'tags' => ['x'], 'ratio' => 1.0], $a->state);
        $this->assertSame('1', $a->stateVersion);
        $this->assertSame('2026-10-23 12:00:00.123456', $a->takenAt->format('Y-m-d H:i:s.u'));
        $this->assertSame(1, $store->latest('b')?->version);
        $this->assertNull($store->latest('missing'));
    }

    /**
     * @param \Closure(): SnapshotStoreInterface $make
     */
    #[DataProvider('storeProvider')]
    public function testNewerSnapshotReplacesOlderButNotTheOtherWayRound(\Closure $make): void
    {
        $store = $make();
        $store->save($this->snapshot('a', 3, ['v' => 3]));
        $store->save($this->snapshot('a', 5, ['v' => 5]));
        $store->save($this->snapshot('a', 4, ['v' => 4]));
        $store->save($this->snapshot('a', 5, ['v' => 'same version']));

        $this->assertSame(['v' => 5], $store->latest('a')?->state);
    }

    /**
     * @param \Closure(): SnapshotStoreInterface $make
     */
    #[DataProvider('storeProvider')]
    public function testDelete(\Closure $make): void
    {
        $store = $make();
        $store->save($this->snapshot('a', 3));
        $store->save($this->snapshot('b', 3));

        $store->delete('a');

        $this->assertNull($store->latest('a'));
        $this->assertNotNull($store->latest('b'));
    }

    public function testPdoSaveJoinsAnOpenTransaction(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $store = new PdoSnapshotStore($pdo);
        $store->ensureTable();

        $pdo->beginTransaction();
        $store->save($this->snapshot('a', 1));
        $this->assertTrue($pdo->inTransaction());
        $pdo->rollBack();

        $this->assertNull($store->latest('a'));
    }

    public function testPdoFailuresAreWrappedInSnapshotException(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $store = new PdoSnapshotStore($pdo);
        $store->ensureTable();
        $pdo->exec('DROP TABLE event_store_snapshots');

        foreach ([
            fn () => $store->save($this->snapshot('a', 1)),
            fn () => $store->latest('a'),
            fn () => $store->delete('a'),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected SnapshotException.');
            } catch (SnapshotException $e) {
                $this->assertStringContainsString('stream "a"', $e->getMessage());
            }
        }
    }

    public function testPdoRejectsNonObjectState(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $store = new PdoSnapshotStore($pdo);
        $store->ensureTable();
        $pdo->exec("INSERT INTO event_store_snapshots VALUES ('a', 1, '1', '\"scalar\"', '2026-10-23 12:00:00.000000')");

        $this->expectException(SnapshotException::class);
        $this->expectExceptionMessage('state is not a JSON object');

        $store->latest('a');
    }

    public function testPdoTableCreationFailureIsWrapped(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA query_only = ON');

        $this->expectException(SnapshotException::class);
        $this->expectExceptionMessage('Failed to create event_store_snapshots table');

        (new PdoSnapshotStore($pdo))->ensureTable();
    }
}
