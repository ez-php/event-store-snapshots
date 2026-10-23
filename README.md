# ez-php/event-store-snapshots

Snapshots for [`ez-php/event-store`](https://github.com/ez-php/event-store) streams. Long streams no longer have to be replayed in full: a load starts from the latest snapshot and replays only the events after it.

## Installation

```bash
composer require ez-php/event-store-snapshots
```

Register the provider after `EventStoreServiceProvider` (both need a `DatabaseInterface` binding):

```php
\EzPhp\EventStore\EventStoreServiceProvider::class,
\EzPhp\EventStoreSnapshots\SnapshotServiceProvider::class,
```

## Usage

```php
use EzPhp\EventStore\EventStoreInterface;
use EzPhp\EventStore\StoredEvent;
use EzPhp\EventStoreSnapshots\EveryNEventsPolicy;
use EzPhp\EventStoreSnapshots\SnapshotStoreInterface;
use EzPhp\EventStoreSnapshots\SnapshottingLoader;

$loader = new SnapshottingLoader(
    $container->make(EventStoreInterface::class),
    $container->make(SnapshotStoreInterface::class),
    new EveryNEventsPolicy(100),   // snapshot once 100+ events had to be replayed
    stateVersion: '1',             // bump when the reducer changes how state is derived
);

$account = $loader->load('account-42', ['balance' => 0], fn (array $state, StoredEvent $e): array => match ($e->eventType) {
    'deposited' => ['balance' => $state['balance'] + $e->payload['amount']],
    'withdrawn' => ['balance' => $state['balance'] - $e->payload['amount']],
    default     => $state,
});

$account->state;     // ['balance' => ...]
$account->version;   // pass as expectedVersion to the next append()

$eventStore->append('account-42', [$withdrawal], expectedVersion: $account->version);
```

`$loader->take(...)` takes the same arguments and always writes a snapshot, e.g. from a maintenance command.

## How it works

- **State is a plain array, the reducer a function**, matching event-store's array payloads. State is stored as JSON, so keep it JSON-serialisable.
- **Snapshots are written on load**, when the policy says so (`EveryNEventsPolicy`, or your own `SnapshotPolicyInterface`).
- **One snapshot per stream**, never moving backwards: an older snapshot never overwrites a newer one.
- **`stateVersion`**: snapshots saved under another state version are ignored and replaced. Bump it whenever the reducer or an upcaster changes the resulting state.
- **Snapshots are a cache.** `SnapshotStoreInterface::delete($stream)` or a lost table only costs a full replay; the event log is never touched.

## Stores

| Store | Use |
|---|---|
| `PdoSnapshotStore` | Production (MySQL) and SQLite. Creates `event_store_snapshots` on first use; the MySQL DDL is in the class doc for a migration. |
| `InMemorySnapshotStore` | Tests and short-lived workers. |

## License

MIT
