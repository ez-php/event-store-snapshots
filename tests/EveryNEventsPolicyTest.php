<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\EventStoreSnapshots\EveryNEventsPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class EveryNEventsPolicyTest
 *
 * @package Tests
 */
#[CoversClass(EveryNEventsPolicy::class)]
final class EveryNEventsPolicyTest extends TestCase
{
    public function testSnapshotsOnceThresholdIsReached(): void
    {
        $policy = new EveryNEventsPolicy(3);

        $this->assertFalse($policy->shouldSnapshot(2, 2));
        $this->assertTrue($policy->shouldSnapshot(3, 3));
        $this->assertTrue($policy->shouldSnapshot(7, 50));
    }

    public function testThresholdMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EveryNEventsPolicy(0);
    }
}
