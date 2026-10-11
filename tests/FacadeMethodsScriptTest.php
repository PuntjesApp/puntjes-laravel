<?php

declare(strict_types=1);

namespace Puntjes\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Puntjes\Laravel\Tests\Fixtures\FixtureClient;

final class FacadeMethodsScriptTest extends TestCase
{
    private string $facade;

    protected function setUp(): void
    {
        $this->facade = (string) tempnam(sys_get_temp_dir(), 'facade');
        copy(__DIR__.'/Fixtures/FixtureFacade.php.txt', $this->facade);
    }

    protected function tearDown(): void
    {
        @unlink($this->facade);
    }

    private function writeMethods(string $file): int
    {
        exec('php '.escapeshellarg(__DIR__.'/../scripts/facade-methods.php').' '.escapeshellarg(FixtureClient::class).' '.escapeshellarg($file).' 2>&1', $out, $exit);

        return $exit;
    }

    public function test_it_lists_the_declared_public_methods_sorted_and_drops_stale_ones(): void
    {
        self::assertSame(0, $this->writeMethods($this->facade));

        self::assertStringContainsString(
            " *\n * @method static \\ArrayObject apple()\n * @method static \\ArrayIterator zebra()\n *\n * @see Something",
            (string) file_get_contents($this->facade),
        );
        self::assertStringNotContainsString('stale()', (string) file_get_contents($this->facade));
        self::assertStringNotContainsString('getIterator', (string) file_get_contents($this->facade));
        self::assertStringNotContainsString('__construct', (string) file_get_contents($this->facade));
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->writeMethods($this->facade);
        $first = (string) file_get_contents($this->facade);

        self::assertSame(0, $this->writeMethods($this->facade));
        self::assertSame($first, file_get_contents($this->facade));
    }

    public function test_a_file_without_method_lines_is_refused_and_left_alone(): void
    {
        file_put_contents($this->facade, "<?php\n\n/** No methods. */\nfinal class FixtureFacade {}\n");

        self::assertSame(1, $this->writeMethods($this->facade));
        self::assertSame("<?php\n\n/** No methods. */\nfinal class FixtureFacade {}\n", file_get_contents($this->facade));
    }
}
