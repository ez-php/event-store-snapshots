<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use EzPhp\EventStoreSnapshots\PdoSnapshotStore;
use EzPhp\EventStoreSnapshots\Snapshot;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * PdoSnapshotStore against MySQL — the production DDL branch (JSON state,
 * DATETIME(6), VARCHAR primary key) that the SQLite tests never reach.
 *
 * Skipped unless DB_HOST is set (Docker: `db`; CI: the job's MySQL service).
 * Uses DB_TESTING_DATABASE, falling back to DB_DATABASE. The table is dropped
 * before and after each test because MySQL DDL commits implicitly.
 */
#[CoversClass(PdoSnapshotStore::class)]
#[UsesClass(Snapshot::class)]
final class PdoSnapshotStoreMysqlTest extends TestCase
{
    private PDO $pdo;

    private PdoSnapshotStore $store;

    protected function setUp(): void
    {
        $host = (string) getenv('DB_HOST');

        if ($host === '') {
            self::markTestSkipped('MySQL not available — set DB_HOST to run this test.');
        }

        $port = (string) (getenv('DB_PORT') ?: '3306');
        $database = (string) (getenv('DB_TESTING_DATABASE') ?: getenv('DB_DATABASE'));

        try {
            $this->pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
                (string) getenv('DB_USERNAME'),
                (string) getenv('DB_PASSWORD'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (PDOException $e) {
            self::markTestSkipped("MySQL not reachable at {$host}:{$port}: {$e->getMessage()}");
        }

        $this->pdo->exec('DROP TABLE IF EXISTS event_store_snapshots');
        $this->store = new PdoSnapshotStore($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS event_store_snapshots');
        }
    }

    public function test_ensure_table_creates_the_mysql_schema(): void
    {
        $this->store->ensureTable();

        $columns = [];

        foreach ($this->pdo->query('SHOW COLUMNS FROM event_store_snapshots') ?: [] as $row) {
            /** @var array{Field: string, Type: string, Key: string} $row */
            $columns[$row['Field']] = [strtolower($row['Type']), $row['Key']];
        }

        self::assertSame('json', $columns['state'][0] ?? null);
        self::assertSame('datetime(6)', $columns['taken_at'][0] ?? null);
        self::assertSame('PRI', $columns['stream_id'][1] ?? null);
    }

    public function test_round_trip_and_version_guard(): void
    {
        $at = new DateTimeImmutable('2026-10-23 12:00:00.654321');
        $this->store->save(new Snapshot('a', 5, ['balance' => 50, 'ratio' => 1.0], '1', $at));
        $this->store->save(new Snapshot('a', 4, ['balance' => 40], '1', $at));

        $snapshot = $this->store->latest('a');

        self::assertNotNull($snapshot);
        self::assertSame(5, $snapshot->version);
        self::assertSame(50, $snapshot->state['balance'] ?? null);
        self::assertSame('2026-10-23 12:00:00.654321', $snapshot->takenAt->format('Y-m-d H:i:s.u'));
    }
}
