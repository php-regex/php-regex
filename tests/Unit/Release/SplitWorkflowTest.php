<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Tests\Unit\Release;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The split workflow pushes every package to its read-only repository with
 * a token that may write to all of them.
 */
final class SplitWorkflowTest extends TestCase
{
    /**
     * A branch run and a tag run push to the same repositories: they wait
     * for each other, whatever ref started them, and none cancels another.
     */
    #[Test]
    public function test_every_split_run_waits_for_the_one_before(): void
    {
        $workflow = self::workflow();

        $this->assertSame(1, preg_match('~^concurrency:\n  group: (.+)\n  cancel-in-progress: (.+)$~m', $workflow, $concurrency));
        $this->assertStringNotContainsString('${{', $concurrency[1]);
        $this->assertSame('false', $concurrency[2]);
    }

    /**
     * splitsh-lite runs with the token in reach: the archive is checked
     * against the checksum pinned here before anything of it runs.
     */
    #[Test]
    public function test_splitsh_lite_is_checked_against_a_pinned_checksum(): void
    {
        $workflow = self::workflow();

        $this->assertMatchesRegularExpression('~^          SHA256: [0-9a-f]{64}$~m', $workflow);
        $this->assertStringContainsString('sha256sum --check --strict', $workflow);
        $this->assertDoesNotMatchRegularExpression('~curl[^\n]*\|~', $workflow);
        $this->assertLessThan(strpos($workflow, 'tar -xzf'), strpos($workflow, 'sha256sum --check --strict'));
    }

    private static function workflow(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/.github/workflows/split.yml');
    }
}
