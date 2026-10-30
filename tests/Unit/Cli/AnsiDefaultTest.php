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

namespace PHPRegex\Tests\Unit\Cli;

use PHPRegex\Cli\Application;
use PHPRegex\Cli\ApplicationFactory;
use PHPRegex\Parser\Text\Path;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Colour follows --ansi or --no-ansi when either is given, and the
 * standard output otherwise: a terminal gets colour, a pipe does not. The
 * last row proves the default is decided by the stream at each run, not
 * inherited from the output's state, whatever the stream of the suite is.
 */
final class AnsiDefaultTest extends TestCase
{
    /**
     * @return iterable<string, array{args: list<string>, ansi: bool|null}>
     */
    public static function provideAnsiOptions(): iterable
    {
        yield 'forced on' => ['args' => ['--ansi'], 'ansi' => true];
        yield 'forced off' => ['args' => ['--no-ansi'], 'ansi' => false];
        yield 'left to the standard output' => ['args' => [], 'ansi' => null];
    }

    /**
     * @param list<string> $args
     */
    #[Test]
    #[DataProvider('provideAnsiOptions')]
    public function test_colour_follows_the_option_or_the_standard_output(array $args, ?bool $ansi): void
    {
        // starts in the opposite state of each expectation, so that the run is what sets it
        $terminal = Application::colourOnTerminal(stream_isatty(\STDOUT), Path::onWindows(), \function_exists('sapi_windows_vt100_support') && sapi_windows_vt100_support(\STDOUT));
        $output = OutputFactory::create(ansi: !($ansi ?? $terminal));

        ob_start();

        try {
            $exitCode = ApplicationFactory::create($output)->run(['regex', ...$args, '--version']);
        } finally {
            ob_end_clean();
        }

        $this->assertSame(0, $exitCode);
        $this->assertSame($ansi ?? $terminal, $output->isAnsi());
    }

    /**
     * The rule itself, on every combination: a terminal gets colour, except
     * a Windows console that does not render the escape sequences.
     *
     * @return iterable<string, array{terminal: bool, windows: bool, vt100: bool, colour: bool}>
     */
    public static function provideTerminals(): iterable
    {
        yield 'a pipe anywhere' => ['terminal' => false, 'windows' => false, 'vt100' => false, 'colour' => false];
        yield 'a pipe on Windows' => ['terminal' => false, 'windows' => true, 'vt100' => false, 'colour' => false];
        yield 'a pipe on Windows with VT100 reported' => ['terminal' => false, 'windows' => true, 'vt100' => true, 'colour' => false];
        yield 'a terminal elsewhere' => ['terminal' => true, 'windows' => false, 'vt100' => false, 'colour' => true];
        yield 'a terminal elsewhere, VT100 reported' => ['terminal' => true, 'windows' => false, 'vt100' => true, 'colour' => true];
        yield 'a Windows console without VT100' => ['terminal' => true, 'windows' => true, 'vt100' => false, 'colour' => false];
        yield 'a Windows console with VT100' => ['terminal' => true, 'windows' => true, 'vt100' => true, 'colour' => true];
        yield 'nothing at all' => ['terminal' => false, 'windows' => false, 'vt100' => true, 'colour' => false];
    }

    #[Test]
    #[DataProvider('provideTerminals')]
    public function test_a_terminal_gets_colour_unless_it_is_a_windows_console_without_vt100(bool $terminal, bool $windows, bool $vt100, bool $colour): void
    {
        $this->assertSame($colour, Application::colourOnTerminal($terminal, $windows, $vt100));
    }
}
