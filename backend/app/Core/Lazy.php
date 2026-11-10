<?php
declare(strict_types=1);

namespace LeenTrack\Core;

/** Defers building a controller (and its DB-backed services) until a request actually reaches it. */
final class Lazy
{
    private ?object $obj = null;

    public function __construct(private readonly \Closure $make)
    {
    }

    /** @param list<mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        $this->obj ??= ($this->make)();
        return $this->obj->$name(...$args);
    }
}
