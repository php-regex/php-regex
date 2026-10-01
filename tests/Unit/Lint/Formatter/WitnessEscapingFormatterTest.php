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

namespace PHPRegex\Tests\Unit\Lint\Formatter;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Formatter\CheckstyleFormatter;
use PHPRegex\Linter\Formatter\ConsoleFormatter;
use PHPRegex\Linter\Formatter\GithubFormatter;
use PHPRegex\Linter\Formatter\JsonFormatter;
use PHPRegex\Linter\Formatter\JunitFormatter;
use PHPRegex\Linter\Formatter\OutputConfiguration;
use PHPRegex\Linter\Formatter\OutputFormatterInterface;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every lint output format carries the witness of a control byte or of a
 * non-ASCII code point in its escaped form: "\x1B" and "\u{E9}", never the
 * raw bytes, in the attack.
 *
 * Each pattern fails preg_match() at 19 pumps followed by "!" (PCRE2 10.49,
 * JIT on and off).
 */
final class WitnessEscapingFormatterTest extends TestCase
{
    #[Test]
    #[DataProvider('provideCases')]
    public function test_lint_output_carries_the_escaped_pump(OutputFormatterInterface $formatter, string $pattern, string $escapedPump): void
    {
        $output = $formatter->format(self::report($pattern));

        $this->assertStringContainsString($escapedPump, $output);
        $this->assertStringNotContainsString("\x1B", $output);
    }

    /**
     * @return iterable<string, array{formatter: OutputFormatterInterface, pattern: string, escapedPump: string}>
     */
    public static function provideCases(): iterable
    {
        $formatters = [
            'checkstyle' => new CheckstyleFormatter(),
            'junit' => new JunitFormatter(),
            'github' => new GithubFormatter(),
            'json' => new JsonFormatter(),
            'console' => new ConsoleFormatter(null, new OutputConfiguration(ansi: false)),
        ];

        foreach ($formatters as $name => $formatter) {
            // JSON escapes the backslash of the literal once more.
            $control = 'json' === $name ? '\\\\x1B' : '\x1B';
            $codePoint = 'json' === $name ? '\\\\u{E9}' : '\u{E9}';
            yield $name.', escape control character' => ['formatter' => $formatter, 'pattern' => '/(\x1b+)+$/', 'escapedPump' => $control];
            yield $name.', e acute under u' => ['formatter' => $formatter, 'pattern' => '/(é+)+$/u', 'escapedPump' => $codePoint];
        }
    }

    private static function report(string $pattern): LintReport
    {
        $analysis = new AnalysisService(RegexParser::create(), redosThreshold: 'low', redosEnabled: true);
        $lint = new LintService($analysis, new PatternSourceCollection([]));

        return $lint->analyze(
            [new PatternOccurrence($pattern, 'file.php', 1, 'php:preg_match()')],
            new LintRequest(['.'], [], 0, checkRedos: true, checkOptimizations: false),
        );
    }
}
