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

use PHPRegex\Cli\Command\DebugCommand;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Redos\Hotspot;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The caret line of "regex debug" marks the primary hotspot on the pattern
 * line it follows; printed after the verdict and the limits, it marked
 * nothing.
 */
final class DebugHeatmapCaretTest extends TestCase
{
    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideRuns')]
    public function test_debug_caret_sits_under_the_hotspot_it_marks(string $pattern, RedosMode $mode, array $arguments): void
    {
        $hotspot = (new RedosAnalyzer())->analyze($pattern, null, $mode)->getPrimaryHotspot();
        $this->assertInstanceOf(Hotspot::class, $hotspot);
        $marked = substr($pattern, 1 + $hotspot->start, max(1, $hotspot->end - $hotspot->start));

        $lines = explode("\n", $this->runDebug([$pattern, ...$arguments]));
        $buffer = implode("\n", $lines);

        $carets = array_keys(array_filter($lines, static fn (string $line): bool => 1 === preg_match('/^ *\^+$/', $line)));
        $this->assertCount(1, $carets, $buffer);

        $patternLine = $lines[$carets[0] - 1] ?? '';
        $this->assertMatchesRegularExpression('/^\s*(?:Pattern|Heatmap):\s+'.preg_quote($pattern, '/').'$/', $patternLine, $buffer);

        $caret = $lines[$carets[0]];
        $column = strspn($caret, ' ');
        $this->assertSame($marked, substr($patternLine, $column, \strlen($caret) - $column), $buffer);
    }

    /**
     * @return iterable<string, array{pattern: string, mode: RedosMode, arguments: list<string>}>
     */
    public static function provideRuns(): iterable
    {
        // a…a! fails at 19 pumps, JIT on and off (PCRE2 10.49).
        yield 'theoretical' => ['pattern' => '/(a+)+$/', 'mode' => RedosMode::Theoretical, 'arguments' => ['--redos-mode=theoretical']];
        yield 'confirmed, after the limits block' => ['pattern' => '/(a+)+$/', 'mode' => RedosMode::Confirmed, 'arguments' => ['--redos-mode=confirmed']];
    }

    /**
     * @param list<string> $arguments
     */
    private function runDebug(array $arguments): string
    {
        $input = new Input('debug', $arguments, new GlobalOptions(false, false, false, true, null, null), []);

        $level = ob_get_level();
        ob_start();

        try {
            (new DebugCommand())->run($input, OutputFactory::create());
        } finally {
            $buffer = (string) ob_get_clean();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return $buffer;
    }
}
