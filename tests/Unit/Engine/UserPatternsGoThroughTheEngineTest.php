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

namespace PHPRegex\Tests\Unit\Engine;

use PHPRegex\Tests\Support\LibrarySource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern the library was given reaches the running engine through
 * src/Parser/Engine only. The library still runs its own regexes (tokenizing,
 * reading escapes): those files are named below. A file whose only preg
 * call ran a pattern it was given is not among them.
 */
final class UserPatternsGoThroughTheEngineTest extends TestCase
{
    private const ENGINE = 'src/Parser/Engine/';

    /**
     * The files that run the library's own regexes. The CLI benchmark
     * (RedosCommand) runs the pattern with the JIT on purpose: it measures
     * what production sees, so it stays out of the engine.
     */
    private const OWN_REGEX_FILES = [
        'src/Parser/Internal/LibraryPcre.php',
        'src/Parser/Analysis/CharSetAnalyzer.php',
        'src/Automata/Transform/HirToNfaTransformer.php',
        'src/Automata/Transform/RegularSubsetValidator.php',
        'src/Laravel/Command/LintCommand.php',
        'src/Laravel/Extractor/ValidationRulePatternSource.php',
        'src/Symfony/Command/LintCommand.php',
        'src/Symfony/Extractor/RoutePatternSource.php',
        'src/Symfony/Routing/RouteConflictAnalyzer.php',
        'src/Symfony/Security/SecurityConfigExtractor.php',
        'src/Cli/Command/HelpCommand.php',
        'src/Cli/Command/LintCommand.php',
        'src/Cli/Command/RedosCommand.php',
        'src/Cli/SelfUpdate/SelfUpdater.php',
        'src/Parser/Internal/CodePointReader.php',
        'src/Parser/Internal/DisplayEscaper.php',
        'src/Parser/Internal/ExtendedClassReader.php',
        'src/Parser/Internal/GroupNameReader.php',
        'src/Parser/Internal/InlineFlags.php',
        'src/Parser/Internal/PatternParser.php',
        'src/Parser/Internal/PcreVerb.php',
        'src/Parser/Internal/VersionCondition.php',
        'src/Parser/Lexer.php',
        'src/Linter/Config/LintConfigValidator.php',
        'src/Linter/Config/ProjectTarget.php',
        'src/Linter/Extraction/TokenBasedExtractionStrategy.php',
        'src/Linter/Formatter/AbstractConsoleTagFormatter.php',
        'src/Linter/Formatter/ConsoleFormatter.php',
        'src/Linter/Formatter/LinkFormatter.php',
        'src/Linter/AnalysisService.php',
        'src/Linter/Rule/BackrefAsOctalInCharClassRule.php',
        'src/Linter/Rule/Support/BackrefTarget.php',
        'src/Linter/Rule/Support/CodePoints.php',
        'src/Linter/Rule/SuspiciousEscapeRule.php',
        'src/Linter/Rule/UndefinedBackrefRule.php',
        'src/Linter/Rule/UselessIFlagRule.php',
        'src/LanguageServer/Converter/PositionConverter.php',
        'src/LanguageServer/Document/RegexFinder.php',
        'src/LanguageServer/Handler/CompletionHandler.php',
        'src/LanguageServer/Protocol/Message.php',
        'src/Parser/Node/QuantifierBounds.php',
        'src/Parser/Printer/PatternPrinter.php',
        'src/Explain/TextExplainer.php',
        'src/Explain/Highlighter/AbstractHighlighter.php',
        'src/Explain/HtmlExplainer.php',
        'src/Parser/Analysis/LengthRangeCalculator.php',
        'src/Parser/Analysis/LiteralExtractor.php',
        'src/Optimizer/Rewriter.php',
        'src/Explain/RailroadSvgRenderer.php',
        'src/Generator/SampleGenerator.php',
        'src/Parser/Validation/Validator.php',
        'src/Parser/Syntax/TokenParser.php',
        'src/Toolkit/Regex.php',
        'src/Parser/ParserOptions.php',
        'src/Parser/RegexParser.php',
        'src/Transpiler/Target/AbstractTargetPrinter.php',
        'src/Transpiler/Target/JavaScript/JavaScriptPrinter.php',
        'src/Transpiler/Target/Python/PythonPrinter.php',
        'src/Transpiler/Target/Python/PythonTarget.php',
    ];

    /**
     * The one place a regex the library owns runs: under at least PHP's
     * default backtrack and recursion limits, whatever the caller set.
     */
    private const LIBRARY_PCRE = 'src/Parser/Internal/LibraryPcre.php';

    /**
     * The CLI benchmark runs the user's pattern with the JIT and the
     * caller's limits on purpose: it measures what production sees, so it
     * goes through neither the engine nor the floor.
     */
    private const USER_PATTERN_BENCHMARKS = ['src/Cli/Command/RedosCommand.php'];

    /**
     * The functions that run a regex; preg_quote() runs none.
     */
    private const RUNS_A_REGEX = '/^preg_(?:match|match_all|replace|replace_callback|replace_callback_array|split|grep|filter)$/';

    /**
     * Reading the engine's last error belongs to whoever ran the pattern:
     * the engine, the Lexer for its own tokenizing regexes, the benchmark.
     */
    private const LAST_ERROR_READERS = [
        'src/Parser/Internal/LibraryPcre.php',
        'src/Parser/Lexer.php',
        'src/Cli/Command/RedosCommand.php',
    ];

    /**
     * An "@" before a preg call hides a warning a pattern raised: the engine
     * captures it with a handler instead, and nothing else runs a pattern
     * that may raise one.
     */
    #[Test]
    public function test_no_preg_call_is_silenced_outside_the_engine(): void
    {
        $silenced = [];
        foreach (LibrarySource::files() as $path => $tokens) {
            if (str_starts_with($path, self::ENGINE)) {
                continue;
            }

            foreach (LibrarySource::functionCalls($tokens, '/^preg_/') as $call) {
                if ($call['silenced']) {
                    $silenced[] = $path.':'.$call['line'].' @'.$call['function'];
                }
            }
        }

        $this->assertSame([], $silenced);
    }

    #[Test]
    public function test_only_files_running_their_own_regexes_call_preg_functions(): void
    {
        $unexpected = [];
        foreach (LibrarySource::files() as $path => $tokens) {
            if (str_starts_with($path, self::ENGINE) || \in_array($path, self::OWN_REGEX_FILES, true)) {
                continue;
            }

            foreach (LibrarySource::functionCalls($tokens, '/^preg_/') as $call) {
                $unexpected[] = $path.':'.$call['line'].' '.$call['function'];
            }
        }

        $this->assertSame([], $unexpected);
    }

    /**
     * A pattern the library was given runs through the engine, under the
     * caller's limits; a regex the library owns runs through the floor
     * helper, under at least PHP's defaults. No regex runs anywhere else,
     * so none of them depends on a caller's small pcre.backtrack_limit.
     */
    #[Test]
    public function test_every_regex_runs_through_the_engine_or_the_library_floor(): void
    {
        $unrouted = [];
        foreach (LibrarySource::files() as $path => $tokens) {
            if (str_starts_with($path, self::ENGINE) || self::LIBRARY_PCRE === $path || \in_array($path, self::USER_PATTERN_BENCHMARKS, true)) {
                continue;
            }

            foreach (LibrarySource::functionCalls($tokens, self::RUNS_A_REGEX) as $call) {
                $unrouted[] = $path.':'.$call['line'].' '.$call['function'];
            }
        }

        $this->assertSame([], $unrouted, \sprintf('%d regex calls run outside the engine and the floor helper.', \count($unrouted)));
    }

    #[Test]
    public function test_the_library_floor_helper_exists_where_the_rule_names_it(): void
    {
        $this->assertArrayHasKey(self::LIBRARY_PCRE, LibrarySource::files());
        $this->assertNotSame([], LibrarySource::functionCalls(LibrarySource::files()[self::LIBRARY_PCRE], self::RUNS_A_REGEX));
    }

    #[Test]
    public function test_only_the_engine_reads_the_last_error_of_a_pattern_it_was_given(): void
    {
        $readers = [];
        foreach (LibrarySource::files() as $path => $tokens) {
            if (str_starts_with($path, self::ENGINE) || \in_array($path, self::LAST_ERROR_READERS, true)) {
                continue;
            }

            foreach (LibrarySource::functionCalls($tokens, '/^preg_last_error(?:_msg)?$/') as $call) {
                $readers[] = $path.':'.$call['line'].' '.$call['function'];
            }
        }

        $this->assertSame([], $readers);
    }

    /**
     * The scan itself sees what it must: a silenced call, a call through a
     * fully qualified name, and not a string that merely names a function.
     */
    #[Test]
    public function test_the_scan_finds_calls_and_ignores_names_in_strings(): void
    {
        $tokens = token_get_all(<<<'PHP'
            <?php
            $a = @preg_match($p, $s);
            $b = \preg_match($p, $s);
            $c = ['preg_match' => 0];
            $d = $object->preg_match($p);
            PHP);

        $calls = LibrarySource::functionCalls($tokens, '/^preg_/');

        $this->assertSame([
            ['function' => 'preg_match', 'line' => 2, 'silenced' => true],
            ['function' => 'preg_match', 'line' => 3, 'silenced' => false],
        ], $calls);
    }
}
