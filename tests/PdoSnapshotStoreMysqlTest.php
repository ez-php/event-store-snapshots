<?php

declare(strict_types=1);

namespace Tests;

use Composer\Autoload\ClassLoader;
use DateTimeImmutable;
use EzPhp\EventStoreSnapshots\PdoSnapshotStore;
use EzPhp\EventStoreSnapshots\Snapshot;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use ReflectionClass;
use RuntimeException;

/**
 * PdoSnapshotStore against MySQL — the production DDL branch (JSON state,
 * DATETIME(6), VARCHAR primary key) that the SQLite tests never reach.
 *
 * Skipped unless DB_HOST is set (Docker: `db`; CI: the job's MySQL service).
 * Also races concurrent save()s from separate PHP processes, which a single
 * connection cannot reproduce. Uses DB_TESTING_DATABASE, falling back to
 * DB_DATABASE. The table is dropped
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

    public function test_concurrent_saves_neither_collide_nor_regress(): void
    {
        $this->store->ensureTable();

        // Eight writers on one fresh stream, versions 1..8 — every one starts at the same instant.
        $this->runSavers('race', range(1, 8));

        $snapshot = $this->store->latest('race');

        self::assertNotNull($snapshot);
        self::assertSame(8, $snapshot->version);
        self::assertSame(8, $snapshot->state['v'] ?? null);
    }

    /**
     * Run one PHP child per version, each saving Snapshot('$streamId', $version) on its own connection.
     *
     * @param string    $streamId
     * @param list<int> $versions
     */
    private function runSavers(string $streamId, array $versions): void
    {
        $loaderFile = (new ReflectionClass(ClassLoader::class))->getFileName();

        if ($loaderFile === false) {
            throw new RuntimeException('Cannot locate the Composer autoloader.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            (string) getenv('DB_HOST'),
            (string) (getenv('DB_PORT') ?: '3306'),
            (string) (getenv('DB_TESTING_DATABASE') ?: getenv('DB_DATABASE')),
        );
        $startAt = microtime(true) + 0.5;
        $handles = [];
        $pipes = [];

        foreach ($versions as $i => $version) {
            $code = sprintf(
                'require %s; $pdo = new PDO(%s, %s, %s, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);'
                . ' $store = new \\EzPhp\\EventStoreSnapshots\\PdoSnapshotStore($pdo);'
                . ' time_nanosleep(0, (int) max(0, (%F - microtime(true)) * 1e9));'
                . ' $store->save(new \\EzPhp\\EventStoreSnapshots\\Snapshot(%s, %d, [\'v\' => %d], \'1\', new DateTimeImmutable()));',
                var_export(dirname($loaderFile, 2) . '/autoload.php', true),
                var_export($dsn, true),
                var_export((string) getenv('DB_USERNAME'), true),
                var_export((string) getenv('DB_PASSWORD'), true),
                $startAt,
                var_export($streamId, true),
                $version,
                $version,
            );
            $handle = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);

            if ($handle === false) {
                throw new RuntimeException('proc_open failed.');
            }

            $handles[$i] = $handle;
        }

        foreach ($handles as $i => $handle) {
            stream_get_contents($pipes[$i][1]);
            $stderr = (string) stream_get_contents($pipes[$i][2]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            self::assertSame(0, proc_close($handle), 'Saver failed: ' . $stderr);
        }
    }
}
