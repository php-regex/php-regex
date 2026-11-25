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
 * The threshold says what it does in one wording wherever it is given: the
 * lowest severity confirmed mode replays and the run fails on, and, for
 * lint, the lowest it reports.
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
        yield 'lint' => ['command' => 'lint', 'line' => 'Lowest ReDoS severity replayed and failing the run, and reported (low, medium, high, critical)'];
        yield 'analyze' => ['command' => 'analyze', 'line' => 'Lowest ReDoS severity replayed and failing the run (low, medium, high, critical)'];
        yield 'debug' => ['command' => 'debug', 'line' => 'Lowest ReDoS severity replayed and failing the run (low, medium, high, critical)'];
    }

    /**
     * --redos-mode takes the same three modes in every command that reads
     * it, off included.
     */
    #[Test]
    #[DataProvider('provideCommands')]
    public function test_the_help_names_the_same_modes(string $command): void
    {
        [, $output] = $this->runRegex([$command, '--help']);

        $this->assertMatchesRegularExpression('/--redos-mode <mode>\s+ReDoS mode \(off, theoretical, confirmed\)/', $output);
    }

    /**
     * @return iterable<string, array{command: string}>
     */
    public static function provideCommands(): iterable
    {
        yield 'lint' => ['command' => 'lint'];
        yield 'analyze' => ['command' => 'analyze'];
        yield 'debug' => ['command' => 'debug'];
    }
}
