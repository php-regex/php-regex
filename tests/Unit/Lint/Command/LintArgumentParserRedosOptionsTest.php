<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Lint\Command;

use PhpRegex\Linter\Config\LintArgumentParser;
use PhpRegex\Linter\Config\LintArguments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The ReDoS options of the lint command: --redos-mode takes theoretical or
 * confirmed, and the two 1.x spellings that had a config twin are refused
 * with what to use instead.
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
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function provideRefusedOptions(): iterable
    {
        yield '--redos-mode=off names --no-redos' => [['--redos-mode=off'], '--no-redos'];
        yield '--redos-mode off names --no-redos' => [['--redos-mode', 'OFF'], '--no-redos'];
        yield '--redos-no-jit' => [['--redos-no-jit'], '--redos-no-jit option was removed'];
        yield 'unknown mode' => [['--redos-mode=fast'], 'expected theoretical or confirmed'];
    }
}
