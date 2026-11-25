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

namespace PHPRegex\Tests\Unit\Lint\Command;

use PHPRegex\Cli\Command\LintArgumentParser;
use PHPRegex\Linter\Config\LintArguments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The ReDoS options of the lint command: --redos-mode takes off,
 * theoretical or confirmed, off as --no-redos, and the 1.x spelling that
 * had a config twin is refused.
 */
final class LintArgumentParserRedosOptionsTest extends TestCase
{
    /**
     * @param list<string> $args
     */
    #[Test]
    #[DataProvider('provideModes')]
    public function test_parse_reads_the_redos_mode_and_enables_the_analysis(array $args, string $mode): void
    {
        $result = (new LintArgumentParser())->parse($args, ['checkRedos' => false]);

        $this->assertNull($result->error);
        $this->assertInstanceOf(LintArguments::class, $result->arguments);
        $this->assertSame($mode, $result->arguments->redosMode);
        $this->assertTrue($result->arguments->checkRedos);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function provideModes(): iterable
    {
        yield 'equals form' => [['--redos-mode=confirmed'], 'confirmed'];
        yield 'separate value' => [['--redos-mode', 'THEORETICAL'], 'theoretical'];
    }

    /**
     * @param list<string> $args
     */
    #[Test]
    #[DataProvider('provideRefusedOptions')]
    public function test_parse_refuses_a_removed_or_unknown_redos_option(array $args, string $message): void
    {
        $result = (new LintArgumentParser())->parse($args);

        $this->assertNull($result->arguments);
        $this->assertStringContainsString($message, (string) $result->error);
        $this->assertStringNotContainsString('removed', (string) $result->error);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function provideRefusedOptions(): iterable
    {
        yield '--redos-no-jit' => [['--redos-no-jit'], 'Unknown option: --redos-no-jit'];
        yield 'unknown mode' => [['--redos-mode=fast'], 'Invalid value for --redos-mode: expected off, theoretical or confirmed.'];
    }

    /**
     * --redos-mode=off skips the analysis, as --no-redos does, whatever
     * regex.json says; the last option of the two kinds wins.
     *
     * @param list<string> $args
     */
    #[Test]
    #[DataProvider('provideModesOff')]
    public function test_parse_reads_the_mode_off_as_no_redos(array $args, bool $checkRedos): void
    {
        $result = (new LintArgumentParser())->parse($args, ['checkRedos' => true]);

        $this->assertNull($result->error);
        $this->assertInstanceOf(LintArguments::class, $result->arguments);
        $this->assertSame($checkRedos, $result->arguments->checkRedos);
    }

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function provideModesOff(): iterable
    {
        yield 'equals form' => [['--redos-mode=off'], false];
        yield 'separate value, any case' => [['--redos-mode', 'OFF'], false];
        yield 'after --redos' => [['--redos', '--redos-mode=off'], false];
        yield 'before --redos' => [['--redos-mode=off', '--redos'], true];
        yield 'before another mode' => [['--redos-mode=off', '--redos-mode=confirmed'], true];
    }
}
