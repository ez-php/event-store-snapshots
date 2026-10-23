<?php

declare(strict_types=1);

namespace EzPhp\EventStoreSnapshots;

use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Class PdoSnapshotStore
 *
 * PDO-backed {@see SnapshotStoreInterface}, one row per stream.
 *
 * Production schema (MySQL):
 *
 *   CREATE TABLE event_store_snapshots (
 *       stream_id     VARCHAR(255) NOT NULL PRIMARY KEY,
 *       version       INT UNSIGNED NOT NULL,
 *       state_version VARCHAR(64)  NOT NULL,
 *       state         JSON         NOT NULL,
 *       taken_at      DATETIME(6)  NOT NULL
 *   );
 *
 * ensureTable() auto-creates the table on first use, adapting the DDL to the
 * PDO driver (SQLite for tests, MySQL for production) — the same pattern as
 * ez-php/event-store's PdoEventStore. Prefer a real migration in production.
 *
 * save() is one version-guarded upsert (MySQL `ON DUPLICATE KEY UPDATE`,
 * SQLite/PostgreSQL `ON CONFLICT … DO UPDATE … WHERE`): an older snapshot never
 * overwrites a newer one, and two loaders saving the same stream at once cannot
 * collide on the primary key or move its snapshot backwards — the row lock the
 * upsert takes serialises them. A read-then-replace would not: under InnoDB's
 * REPEATABLE READ its SELECT is a non-locking snapshot read.
 *
 * @package EzPhp\EventStoreSnapshots
 */
final class PdoSnapshotStore implements SnapshotStoreInterface
{
    private const string DATE_FORMAT = 'Y-m-d H:i:s.u';

    private bool $tableChecked = false;

    /**
     * @param PDO $pdo
     */
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function save(Snapshot $snapshot): void
    {
        $this->ensureTable();

        $bindings = [
            'stream_id' => $snapshot->streamId,
            'version' => $snapshot->version,
            'state_version' => $snapshot->stateVersion,
            'state' => '',
            'taken_at' => $snapshot->takenAt->format(self::DATE_FORMAT),
        ];

        try {
            $bindings['state'] = json_encode($snapshot->state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $sql = 'INSERT INTO event_store_snapshots (stream_id, version, state_version, state, taken_at)'
                . ' VALUES (:stream_id, :version, :state_version, :state, :taken_at)';

            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                // MySQL applies the assignments left to right, so `version` goes last: the
                // conditions before it still compare against the stored version. Separate
                // placeholders because VALUES() in ON DUPLICATE KEY is deprecated (8.0.20).
                $sql .= ' ON DUPLICATE KEY UPDATE'
                    . ' state_version = IF(:v1 > version, :new_state_version, state_version),'
                    . ' state = IF(:v2 > version, :new_state, state),'
                    . ' taken_at = IF(:v3 > version, :new_taken_at, taken_at),'
                    . ' version = IF(:v4 > version, :v5, version)';
                $bindings += [
                    'v1' => $snapshot->version,
                    'v2' => $snapshot->version,
                    'v3' => $snapshot->version,
                    'v4' => $snapshot->version,
                    'v5' => $snapshot->version,
                    'new_state_version' => $bindings['state_version'],
                    'new_state' => $bindings['state'],
                    'new_taken_at' => $bindings['taken_at'],
                ];
            } else {
                // SQLite 3.24+ and PostgreSQL 9.5+.
                $sql .= ' ON CONFLICT (stream_id) DO UPDATE SET'
                    . ' version = excluded.version, state_version = excluded.state_version,'
                    . ' state = excluded.state, taken_at = excluded.taken_at'
                    . ' WHERE excluded.version > event_store_snapshots.version';
            }

            $this->pdo->prepare($sql)->execute($bindings);
        } catch (Throwable $e) {
            throw new SnapshotException("Failed to save snapshot of stream \"{$snapshot->streamId}\": {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function latest(string $streamId): ?Snapshot
    {
        $this->ensureTable();

        try {
            $stmt = $this->pdo->prepare(
                'SELECT version, state_version, state, taken_at FROM event_store_snapshots WHERE stream_id = ?'
            );
            $stmt->execute([$streamId]);

            /** @var array{version: int|string, state_version: string, state: string, taken_at: string}|false $row */
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                return null;
            }

            $state = json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($state)) {
                throw new SnapshotException('state is not a JSON object');
            }

            /** @var array<string, mixed> $state */
            return new Snapshot(
                $streamId,
                (int) $row['version'],
                $state,
                $row['state_version'],
                new DateTimeImmutable($row['taken_at']),
            );
        } catch (Throwable $e) {
            throw new SnapshotException("Failed to read snapshot of stream \"{$streamId}\": {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $streamId): void
    {
        $this->ensureTable();

        try {
            $this->pdo->prepare('DELETE FROM event_store_snapshots WHERE stream_id = ?')->execute([$streamId]);
        } catch (Throwable $e) {
            throw new SnapshotException("Failed to delete snapshot of stream \"{$streamId}\": {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Create the event_store_snapshots table if it does not yet exist.
     * Runs at most once per instance. Adapts DDL to the PDO driver.
     *
     * @throws SnapshotException When the table cannot be created.
     *
     * @return void
     */
    public function ensureTable(): void
    {
        if ($this->tableChecked) {
            return;
        }

        $this->tableChecked = true;

        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $this->pdo->exec(
                    'CREATE TABLE IF NOT EXISTS event_store_snapshots (
                        stream_id     TEXT    NOT NULL PRIMARY KEY,
                        version       INTEGER NOT NULL,
                        state_version TEXT    NOT NULL,
                        state         TEXT    NOT NULL,
                        taken_at      TEXT    NOT NULL
                    )'
                );
            } else {
                $this->pdo->exec(
                    'CREATE TABLE IF NOT EXISTS event_store_snapshots (
                        stream_id     VARCHAR(255) NOT NULL PRIMARY KEY,
                        version       INT UNSIGNED NOT NULL,
                        state_version VARCHAR(64)  NOT NULL,
                        state         JSON         NOT NULL,
                        taken_at      DATETIME(6)  NOT NULL
                    )'
                );
            }
        } catch (Throwable $e) {
            throw new SnapshotException("Failed to create event_store_snapshots table: {$e->getMessage()}", 0, $e);
        }
    }
}
