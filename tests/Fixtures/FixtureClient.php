<?php

declare(strict_types=1);

namespace Puntjes\Laravel\Tests\Fixtures;

final class FixtureClient extends \ArrayObject
{
    public function __construct()
    {
        parent::__construct();
    }

    public function zebra(): \ArrayIterator
    {
        return new \ArrayIterator;
    }

    public function apple(): \ArrayObject
    {
        return new \ArrayObject;
    }

    public function byItem(\ArrayObject $item, ?\Countable $count, string $id): ?\ArrayIterator
    {
        return null;
    }

    private function hidden(): void {}
}
