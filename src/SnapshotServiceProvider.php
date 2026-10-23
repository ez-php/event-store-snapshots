<?php

declare(strict_types=1);

namespace EzPhp\EventStoreSnapshots;

use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\DatabaseInterface;
use EzPhp\Contracts\ServiceProvider;

/**
 * Class SnapshotServiceProvider
 *
 * Binds SnapshotStoreInterface to the PDO-backed PdoSnapshotStore over the
 * application's DatabaseInterface. Like EventStoreServiceProvider, it does not
 * degrade silently without a database binding: resolving the store then fails
 * with the container's own error.
 *
 * SnapshottingLoader is not bound — its policy and state version are
 * per-aggregate decisions, so the application constructs one per use case.
 *
 * @package EzPhp\EventStoreSnapshots
 */
final class SnapshotServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(SnapshotStoreInterface::class, function (ContainerInterface $app): SnapshotStoreInterface {
            return new PdoSnapshotStore($app->make(DatabaseInterface::class)->getPdo());
        });
    }
}
