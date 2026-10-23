<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Contracts\DatabaseInterface;
use EzPhp\EventStoreSnapshots\PdoSnapshotStore;
use EzPhp\EventStoreSnapshots\SnapshotServiceProvider;
use EzPhp\EventStoreSnapshots\SnapshotStoreInterface;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Tests\Support\SnapshotFakeContainer;

/**
 * Class SnapshotServiceProviderTest
 *
 * @package Tests
 */
#[CoversClass(SnapshotServiceProvider::class)]
#[UsesClass(PdoSnapshotStore::class)]
final class SnapshotServiceProviderTest extends TestCase
{
    public function testBindsPdoSnapshotStoreOverTheDatabaseConnection(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $container = new SnapshotFakeContainer();
        $container->instance(DatabaseInterface::class, new class ($pdo) implements DatabaseInterface {
            public function __construct(private readonly PDO $pdo)
            {
            }

            public function query(string $sql, array $bindings = []): array
            {
                return [];
            }

            public function execute(string $sql, array $bindings = []): int
            {
                return 0;
            }

            public function transaction(callable $fn): mixed
            {
                return $fn();
            }

            public function getPdo(): PDO
            {
                return $this->pdo;
            }
        });

        (new SnapshotServiceProvider($container))->register();

        $this->assertTrue($container->wasBound(SnapshotStoreInterface::class));
        $store = $container->make(SnapshotStoreInterface::class);
        $this->assertInstanceOf(PdoSnapshotStore::class, $store);

        $store->delete('any'); // creates the table on the bound connection
        $result = $pdo->query("SELECT 1 FROM sqlite_master WHERE name = 'event_store_snapshots'");
        $this->assertNotFalse($result);
        $this->assertNotFalse($result->fetchColumn());
    }

    public function testResolvingWithoutDatabaseFailsLoudly(): void
    {
        $container = new SnapshotFakeContainer();
        (new SnapshotServiceProvider($container))->register();

        $this->expectException(RuntimeException::class);

        $container->make(SnapshotStoreInterface::class);
    }
}
