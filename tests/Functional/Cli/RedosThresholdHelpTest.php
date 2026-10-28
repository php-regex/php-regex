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

namespace PHPRegex\Tests\Functional\Cli;

use PHPRegex\Tests\Support\RunsRegexCli;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The threshold says what it does where it is given: lint reports the
 * verdicts at or above it; analyze and debug report the one pattern they
 * are given, and replay its verdict in confirmed mode from it.
 */
final class RedosThresholdHelpTest extends TestCase
{
    use RunsRegexCli;

    #[Test]
    #[DataProvider('provideHelps')]
    public function test_the_help_says_what_the_threshold_does(string $command, string $line): void
    {
        [, $output] = $this->runRegex([$command, '--help']);

        $this->assertMatchesRegularExpression('/--redos-threshold <sev>\s+'.preg_quote($line, '/').'/', $output);
    }

    /**
     * @return iterable<string, array{command: string, line: string}>
     */
    public static function provideHelps(): iterable
    {
        yield 'lint' => ['command' => 'lint', 'line' => 'Lowest ReDoS severity reported (low, medium, high, critical)'];
        yield 'analyze' => ['command' => 'analyze', 'line' => 'Lowest ReDoS severity confirmed mode replays (low, medium, high, critical)'];
        yield 'debug' => ['command' => 'debug', 'line' => 'Lowest ReDoS severity confirmed mode replays (low, medium, high, critical)'];
    }
}
