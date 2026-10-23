<?php

declare(strict_types=1);

namespace Tests\Support;

use EzPhp\Contracts\ContainerInterface;
use RuntimeException;

/**
 * Minimal ContainerInterface stub for service provider tests.
 *
 * Bindings are stored and executed on make(); other instances can be seeded
 * directly via instance().
 *
 * Named `SnapshotFakeContainer` rather than the more obvious `FakeContainer`:
 * the shared `Tests\` PSR-4 prefix maps to every module's `tests/` directory in
 * one array, and Composer's autoloader resolves a class to the first directory
 * in that list containing a matching file — a `Tests\Support\FakeContainer` here
 * would silently resolve to a different module's `FakeContainer` (with a
 * different constructor) in the aggregated root test run instead of this one.
 */
final class SnapshotFakeContainer implements ContainerInterface
{
    /** @var array<string, callable(ContainerInterface): object> */
    private array $bindings = [];

    /** @var array<string, object> */
    private array $instances = [];

    public function bind(string $abstract, string|callable|null $factory = null): static
    {
        if (is_callable($factory)) {
            $this->bindings[$abstract] = $factory;
        }

        return $this;
    }

    /**
     * @template T of object
     * @param class-string<T> $abstract
     * @return T
     */
    public function make(string $abstract): mixed
    {
        if (isset($this->instances[$abstract])) {
            $instance = $this->instances[$abstract];
            assert($instance instanceof $abstract);

            return $instance;
        }

        if (isset($this->bindings[$abstract])) {
            $result = ($this->bindings[$abstract])($this);
            assert($result instanceof $abstract);

            return $result;
        }

        throw new RuntimeException("No binding for {$abstract}");
    }

    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    public function has(string $abstract): bool
    {
        return isset($this->instances[$abstract]) || isset($this->bindings[$abstract]);
    }

    public function wasBound(string $abstract): bool
    {
        return isset($this->bindings[$abstract]);
    }
}
