<?php

declare(strict_types=1);

namespace Puntjes\Laravel\Tests;

final class RedProbeTest extends TestCase
{
    public function test_ci_turns_red(): void
    {
        self::assertTrue(false);
    }
}
