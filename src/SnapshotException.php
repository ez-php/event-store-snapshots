<?php

declare(strict_types=1);

namespace EzPhp\EventStoreSnapshots;

/**
 * Class SnapshotException
 *
 * Thrown when a snapshot store cannot read or write (database error, table
 * creation failure, undecodable state).
 *
 * @package EzPhp\EventStoreSnapshots
 */
final class SnapshotException extends \RuntimeException
{
}
