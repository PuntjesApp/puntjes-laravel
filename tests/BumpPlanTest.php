<?php

declare(strict_types=1);

namespace Puntjes\Laravel\Tests;

use PHPUnit\Framework\TestCase;

final class BumpPlanTest extends TestCase
{
    /** @return array{0: int, 1: array<string, string>} */
    private function plan(string ...$args): array
    {
        exec('php '.escapeshellarg(__DIR__.'/../scripts/bump-plan.php').' '.implode(' ', array_map('escapeshellarg', $args)).' 2>&1', $out, $exit);
        $pairs = [];
        foreach ($out as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $pairs[$key] = $value;
        }

        return [$exit, $pairs];
    }

    public function test_a_core_minor_is_a_feat_that_merges_itself(): void
    {
        [$exit, $plan] = $this->plan('v2.1.0', '^2.0.0', '');

        self::assertSame(0, $exit);
        self::assertSame([
            'skip' => 'false',
            'constraint' => '^2.1.0',
            'commit_type' => 'feat(deps)',
            'auto_merge' => 'true',
            'branch' => 'bump/core-v2.1.0',
            'close' => '',
        ], $plan);
    }

    public function test_a_core_patch_is_a_fix_that_merges_itself(): void
    {
        [, $plan] = $this->plan('v2.1.1', '^2.1.0', '');

        self::assertSame('fix(deps)', $plan['commit_type']);
        self::assertSame('true', $plan['auto_merge']);
    }

    public function test_a_core_major_is_breaking_and_waits_for_a_person(): void
    {
        [, $plan] = $this->plan('v3.0.0', '^2.4.1', '');

        self::assertSame('feat(deps)!', $plan['commit_type']);
        self::assertSame('false', $plan['auto_merge']);
    }

    public function test_a_tag_at_the_floor_is_skipped(): void
    {
        [$exit, $plan] = $this->plan('v2.0.0', '^2.0.0', '');

        self::assertSame(0, $exit);
        self::assertSame('true', $plan['skip']);
    }

    public function test_a_tag_below_the_floor_is_skipped(): void
    {
        [, $plan] = $this->plan('v2.1.0', '^2.2.0', '');

        self::assertSame('true', $plan['skip']);
    }

    public function test_a_short_floor_reads_as_its_dot_zero(): void
    {
        self::assertSame('true', $this->plan('v2.0.0', '^2.0', '')[1]['skip']);
        self::assertSame('false', $this->plan('v2.0.1', '^2.0', '')[1]['skip']);
    }

    public function test_a_newer_open_bump_makes_an_older_tag_skip(): void
    {
        [, $plan] = $this->plan('v2.1.0', '^2.0.0', "bump/core-v2.2.0 feat(deps): require puntjes/php-sdk v2.2.0\n");

        self::assertSame('true', $plan['skip']);
        self::assertSame('', $plan['close']);
    }

    public function test_an_older_open_bump_is_closed(): void
    {
        $open = "bump/core-v2.1.0 feat(deps): require puntjes/php-sdk v2.1.0\nfeature/other Something else\n";

        [, $plan] = $this->plan('v2.2.0', '^2.0.0', $open);

        self::assertSame('false', $plan['skip']);
        self::assertSame('bump/core-v2.1.0', $plan['close']);
    }

    public function test_a_minor_after_an_open_major_is_still_a_major(): void
    {
        [, $plan] = $this->plan('v3.1.0', '^2.4.0', "bump/core-v3.0.0 feat(deps)!: require puntjes/php-sdk v3.0.0\n");

        self::assertSame('bump/core-v3.0.0', $plan['close']);
        self::assertSame('feat(deps)!', $plan['commit_type']);
        self::assertSame('false', $plan['auto_merge']);
    }

    public function test_a_tag_that_is_not_a_version_is_refused(): void
    {
        self::assertSame(1, $this->plan('v2.1', '^2.0.0', '')[0]);
        self::assertSame(1, $this->plan('2.1.0', '^2.0.0', '')[0]);
        self::assertSame(1, $this->plan('v2.1.0; rm -rf /', '^2.0.0', '')[0]);
    }

    public function test_a_constraint_without_a_caret_floor_is_refused(): void
    {
        self::assertSame(1, $this->plan('v2.1.0', '>=2.0', '')[0]);
    }
}
