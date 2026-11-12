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

use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RangePatterns;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Tests\Support\SinglePatternRange;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every command that reads one pattern judges a --php-version range as
 * lint does: its own output, the explanation, the tree, the diagram, the
 * ReDoS verdict, the laid-out or translated pattern, is computed once, at
 * the floor, and is still printed; the pattern is validated at every later
 * version. A later version that refuses it is an error and the run exits
 * 1; one that reads it into another tree is a warning, exit 0. format,
 * transpile and highlight print their product alone on stdout: the range's
 * words go to stderr.
 *
 * transpile names the PHP versions judged "php_target": its "target" is
 * the dialect.
 *
 * transpile, compare and graph refuse every construct a later PHP refuses
 * ("\K" in a lookaround, "\C" under "u") at the floor already: they have
 * no refusal row, only the warning one.
 */
final class SinglePatternCommandRangeTest extends TestCase
{
    use RunsRegexCli;
    use SinglePatternRange;
    use TemporaryProject;

    private const RANGE = '--php-version=>=8.1';

    private const FLOOR = '--php-version=8.1';

    /**
     * @param list<string> $command the command line after the global options, the pattern included
     */
    #[Test]
    #[DataProvider('provideCommandsOnARefusedPattern')]
    public function test_a_command_exits_1_on_a_later_refusal_and_still_prints_its_output(array $command, bool $productOnStdout): void
    {
        self::assertEngineKeepInLookbehind();
        $this->enterProject();

        [$floorExitCode, $floorStdout, $floorStderr] = $this->runRegex([self::FLOOR, ...$command]);
        [$exitCode, $stdout, $stderr] = $this->runRegex([self::RANGE, ...$command]);

        // PHP 8.1 alone compiles the pattern: the command does its work.
        $this->assertSame(0, $floorExitCode, $floorStdout.$floorStderr);
        $this->assertSame(1, $exitCode, $stdout.$stderr);
        $refusal = self::refusedOnMessage('PHP 8.5 and later', 80500, RangePatterns::KEEP_IN_LOOKBEHIND);

        if ($productOnStdout) {
            $this->assertSame($floorStdout, $stdout, 'stdout holds the product alone, as at the floor.');
            $this->assertStringContainsString($refusal, $stderr);

            return;
        }

        $this->assertStringContainsString($refusal, $stdout.$stderr);
        foreach (self::stableLines($floorStdout) as $line) {
            $this->assertStringContainsString($line, $stdout, 'The output computed at the floor is still printed.');
        }
    }

    /**
     * @return iterable<string, array{command: list<string>, productOnStdout: bool}>
     */
    public static function provideCommandsOnARefusedPattern(): iterable
    {
        yield 'analyze' => ['command' => ['analyze', RangePatterns::KEEP_IN_LOOKBEHIND], 'productOnStdout' => false];
        yield 'explain' => ['command' => ['explain', RangePatterns::KEEP_IN_LOOKBEHIND], 'productOnStdout' => false];
        yield 'redos' => ['command' => ['redos', RangePatterns::KEEP_IN_LOOKBEHIND], 'productOnStdout' => false];
        yield 'parse' => ['command' => ['parse', RangePatterns::KEEP_IN_LOOKBEHIND], 'productOnStdout' => false];
        yield 'parse --validate' => ['command' => ['parse', RangePatterns::KEEP_IN_LOOKBEHIND, '--validate'], 'productOnStdout' => false];
        yield 'diagram' => ['command' => ['diagram', RangePatterns::KEEP_IN_LOOKBEHIND], 'productOnStdout' => false];
        yield 'debug' => ['command' => ['debug', RangePatterns::KEEP_IN_LOOKBEHIND], 'productOnStdout' => false];
        yield 'format' => ['command' => ['format', RangePatterns::KEEP_IN_LOOKBEHIND], 'productOnStdout' => true];
        yield 'format --compact' => ['command' => ['format', RangePatterns::KEEP_IN_LOOKBEHIND, '--compact'], 'productOnStdout' => true];
        yield 'highlight' => ['command' => ['highlight', RangePatterns::KEEP_IN_LOOKBEHIND], 'productOnStdout' => true];
    }

    /**
     * @param list<string> $command the command line after the global options, the pattern included
     */
    #[Test]
    #[DataProvider('provideCommandsOnAPatternParsedDifferently')]
    public function test_a_command_warns_when_a_later_php_parses_the_pattern_differently(array $command, bool $productOnStdout): void
    {
        self::assertEngineReadsQuantifierWithoutMin();
        $this->enterProject();

        [$floorExitCode, $floorStdout, $floorStderr] = $this->runRegex([self::FLOOR, ...$command]);
        [$exitCode, $stdout, $stderr] = $this->runRegex([self::RANGE, ...$command]);

        $this->assertSame($floorExitCode, $exitCode, 'A warning leaves the exit code of the floor: '.$stdout.$stderr);
        $warning = self::parsesDifferentlyMessage(self::firstPointReadingQuantifierOtherwise(RangePatterns::FROM_8_1));

        if ($productOnStdout) {
            $this->assertSame($floorStdout, $stdout, 'stdout holds the product alone, as at the floor.');
            $this->assertStringContainsString($warning, $stderr);

            return;
        }

        $this->assertStringContainsString($warning, $stdout.$stderr);
        $this->assertStringNotContainsString($warning, $floorStdout.$floorStderr, 'A single version has no later version to compare with.');
        foreach (self::stableLines($floorStdout) as $line) {
            $this->assertStringContainsString($line, $stdout, 'The output computed at the floor is still printed.');
        }
    }

    /**
     * @return iterable<string, array{command: list<string>, productOnStdout: bool}>
     */
    public static function provideCommandsOnAPatternParsedDifferently(): iterable
    {
        yield 'analyze' => ['command' => ['analyze', RangePatterns::QUANTIFIER_WITHOUT_MIN], 'productOnStdout' => false];
        yield 'explain' => ['command' => ['explain', RangePatterns::QUANTIFIER_WITHOUT_MIN], 'productOnStdout' => false];
        yield 'redos' => ['command' => ['redos', RangePatterns::QUANTIFIER_WITHOUT_MIN], 'productOnStdout' => false];
        yield 'parse' => ['command' => ['parse', RangePatterns::QUANTIFIER_WITHOUT_MIN], 'productOnStdout' => false];
        yield 'diagram' => ['command' => ['diagram', RangePatterns::QUANTIFIER_WITHOUT_MIN], 'productOnStdout' => false];
        yield 'debug' => ['command' => ['debug', RangePatterns::QUANTIFIER_WITHOUT_MIN], 'productOnStdout' => false];
        yield 'compare, the first pattern' => ['command' => ['compare', RangePatterns::QUANTIFIER_WITHOUT_MIN, '/b/'], 'productOnStdout' => false];
        // compare reads two patterns: each is judged over the range.
        yield 'compare, the second pattern' => ['command' => ['compare', '/b/', RangePatterns::QUANTIFIER_WITHOUT_MIN], 'productOnStdout' => false];
        yield 'graph' => ['command' => ['graph', RangePatterns::QUANTIFIER_WITHOUT_MIN], 'productOnStdout' => false];
        yield 'format' => ['command' => ['format', RangePatterns::QUANTIFIER_WITHOUT_MIN], 'productOnStdout' => true];
        yield 'highlight' => ['command' => ['highlight', RangePatterns::QUANTIFIER_WITHOUT_MIN], 'productOnStdout' => true];
        // At the floor "{,3}" is text, and the translation says so.
        yield 'transpile' => ['command' => ['transpile', RangePatterns::QUANTIFIER_WITHOUT_MIN], 'productOnStdout' => true];
        yield 'transpile to Python' => ['command' => ['transpile', RangePatterns::QUANTIFIER_WITHOUT_MIN, '--target=python'], 'productOnStdout' => true];
    }

    /**
     * The JSON of a command gains lint's "target" and the "range" list;
     * every key it had keeps the floor's value.
     *
     * @param list<string> $command the command line after the global options, the pattern included
     * @param list<string> $kept    the keys whose value is the floor-only run's
     */
    #[Test]
    #[DataProvider('provideJsonCommandsOnARefusedPattern')]
    public function test_a_json_document_gains_the_target_and_the_range(string $address, array $command, array $kept): void
    {
        self::assertEngineKeepInLookbehind();
        $directory = $this->enterProject();

        [, $floorStdout] = $this->runRegex([self::FLOOR, ...$command, '--format=json']);
        [$exitCode, $stdout] = $this->runRegex([self::RANGE, ...$command, '--format=json']);

        $this->assertSame(1, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape($address, $document);
        $this->assertSame(ProjectTarget::resolve('>=8.1', null, [], $directory, [])->toArray(), $document['target'] ?? null);
        self::assertRange(
            $document['range'] ?? null,
            RangePatterns::FROM_8_1,
            RangePatterns::KEEP_IN_LOOKBEHIND,
            static fn (int $point): bool => $point < 80500,
            static fn (int $point): bool => false,
        );

        $floor = JsonContract::decodeDocument($floorStdout);
        foreach ($kept as $key) {
            $this->assertArrayHasKey($key, $floor, $floorStdout);
            $this->assertSame($floor[$key], $document[$key] ?? null, $key.' keeps the value the floor gives it.');
        }
    }

    /**
     * @return iterable<string, array{address: string, command: list<string>, kept: list<string>}>
     */
    public static function provideJsonCommandsOnARefusedPattern(): iterable
    {
        yield 'analyze' => ['address' => 'analyze', 'command' => ['analyze', RangePatterns::KEEP_IN_LOOKBEHIND], 'kept' => ['pattern', 'runtime', 'parse', 'validation', 'redos', 'explain']];
        yield 'explain' => ['address' => 'explain', 'command' => ['explain', RangePatterns::KEEP_IN_LOOKBEHIND], 'kept' => ['pattern', 'explanation']];
        // The benchmark's timings differ from run to run.
        yield 'redos' => ['address' => 'redos', 'command' => ['redos', RangePatterns::KEEP_IN_LOOKBEHIND], 'kept' => ['pattern', 'safe_pattern', 'input', 'settings']];
        yield 'format' => ['address' => 'format', 'command' => ['format', RangePatterns::KEEP_IN_LOOKBEHIND], 'kept' => ['pattern', 'formatted', 'changed', 'refusal']];
        yield 'debug' => ['address' => 'debug', 'command' => ['debug', RangePatterns::KEEP_IN_LOOKBEHIND], 'kept' => ['pattern', 'runtime', 'validation', 'analysis', 'input']];
        yield 'validate' => ['address' => 'validate', 'command' => ['validate', RangePatterns::KEEP_IN_LOOKBEHIND], 'kept' => ['pattern', 'validation']];
    }

    /**
     * transpile's JSON already has a "target", the dialect: it keeps it,
     * names the PHP versions judged "php_target", lint's object, and gains
     * the "range" list.
     */
    #[Test]
    public function test_the_transpile_document_keeps_its_dialect_target_and_gains_the_php_target_and_the_range(): void
    {
        self::assertEngineReadsQuantifierWithoutMin();
        $directory = $this->enterProject();

        [, $floorStdout] = $this->runRegex([self::FLOOR, 'transpile', RangePatterns::QUANTIFIER_WITHOUT_MIN, '--format=json']);
        [$exitCode, $stdout] = $this->runRegex([self::RANGE, 'transpile', RangePatterns::QUANTIFIER_WITHOUT_MIN, '--format=json']);

        $this->assertSame(0, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('transpile', $document);
        $this->assertSame('javascript', $document['target'] ?? null);
        $this->assertSame(ProjectTarget::resolve('>=8.1', null, [], $directory, [])->toArray(), $document['php_target'] ?? null);
        self::assertRange(
            $document['range'] ?? null,
            RangePatterns::FROM_8_1,
            RangePatterns::QUANTIFIER_WITHOUT_MIN,
            static fn (int $point): bool => true,
            self::readsQuantifierOtherwiseThanTheFloor(RangePatterns::FROM_8_1),
        );

        $floor = JsonContract::decodeDocument($floorStdout);
        foreach (['target', 'source', 'pattern', 'flags', 'literal', 'constructor', 'warnings', 'notes'] as $key) {
            $this->assertSame($floor[$key] ?? null, $document[$key] ?? null, $key.' keeps the value the floor gives it.');
        }
    }

    /**
     * The lines of a run's output that do not move from one run to the
     * next: the rows of redos' benchmark table are timings.
     *
     * @return list<string>
     */
    private static function stableLines(string $output): array
    {
        $lines = [];
        foreach (explode("\n", $output) as $line) {
            // "vuln     | no     |       0.03 | ...", its header and its rule.
            if ('' === trim($line) || 1 === preg_match('/^\w+ +\|/', $line) || 1 === preg_match('/^-+$/', $line)) {
                continue;
            }
            $lines[] = $line;
        }

        return $lines;
    }
}
