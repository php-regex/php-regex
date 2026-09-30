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

namespace RegexParser\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Tests\Support\LibrarySource;

/**
 * A pattern the library was given reaches the running engine through
 * src/Engine only. The library still runs its own regexes (tokenizing,
 * reading escapes): those files are named below. A file whose only preg
 * call ran a pattern it was given is not among them.
 */
final class UserPatternsGoThroughTheEngineTest extends TestCase
{
    private const ENGINE = 'src/Engine/';

    /**
     * The files that run the library's own regexes. The CLI benchmark
     * (RedosCommand) runs the pattern with the JIT on purpose: it measures
     * what production sees, so it stays out of the engine.
     */
    private const OWN_REGEX_FILES = [
        'src/Analysis/CharSetAnalyzer.php',
        'src/Automata/Transform/AstToNfaTransformer.php',
        'src/Automata/Transform/RegularSubsetValidator.php',
        'src/Automata/Unicode/CodePointHelper.php',
        'src/Bridge/Laravel/Command/LintCommand.php',
        'src/Bridge/Laravel/Extractor/ValidationRuleExtractor.php',
        'src/Bridge/Symfony/Command/RegexLintCommand.php',
        'src/Bridge/Symfony/Extractor/RouteRegexPatternSource.php',
        'src/Bridge/Symfony/Routing/RouteConflictAnalyzer.php',
        'src/Bridge/Symfony/Security/SecurityConfigExtractor.php',
        'src/Cli/Command/HelpCommand.php',
        'src/Cli/Command/LintCommand.php',
        'src/Cli/Command/RedosCommand.php',
        'src/Cli/SelfUpdate/SelfUpdater.php',
        'src/Internal/CodePointReader.php',
        'src/Internal/DisplayEscaper.php',
        'src/Internal/ExtendedClassReader.php',
        'src/Internal/GroupNameReader.php',
        'src/Internal/InlineFlags.php',
        'src/Internal/PatternParser.php',
        'src/Internal/PcreVerb.php',
        'src/Internal/VersionCondition.php',
        'src/Lexer.php',
        'src/Lint/Command/LintConfigValidator.php',
        'src/Lint/Command/ProjectTarget.php',
        'src/Lint/Extraction/TokenBasedExtractionStrategy.php',
        'src/Lint/Formatter/AbstractConsoleTagFormatter.php',
        'src/Lint/Formatter/ConsoleFormatter.php',
        'src/Lint/Formatter/LinkFormatter.php',
        'src/Lint/RegexAnalysisService.php',
        'src/Lint/Rule/BackrefAsOctalInCharClassRule.php',
        'src/Lint/Rule/Support/BackrefTarget.php',
        'src/Lint/Rule/Support/CodePoints.php',
        'src/Lint/Rule/SuspiciousEscapeRule.php',
        'src/Lint/Rule/UndefinedBackrefRule.php',
        'src/Lint/Rule/UselessIFlagRule.php',
        'src/Lsp/Converter/PositionConverter.php',
        'src/Lsp/Document/RegexFinder.php',
        'src/Lsp/Handler/CompletionHandler.php',
        'src/Lsp/Protocol/Message.php',
        'src/Node/QuantifierBounds.php',
        'src/NodeVisitor/CompilerNodeVisitor.php',
        'src/NodeVisitor/ExplainNodeVisitor.php',
        'src/NodeVisitor/HighlighterVisitor.php',
        'src/NodeVisitor/HtmlExplainNodeVisitor.php',
        'src/NodeVisitor/LengthRangeNodeVisitor.php',
        'src/NodeVisitor/LiteralExtractorNodeVisitor.php',
        'src/NodeVisitor/OptimizerNodeVisitor.php',
        'src/NodeVisitor/RailroadSvgVisitor.php',
        'src/NodeVisitor/SampleGeneratorNodeVisitor.php',
        'src/NodeVisitor/ValidatorNodeVisitor.php',
        'src/Parser.php',
        'src/Regex.php',
        'src/RegexOptions.php',
        'src/RegexParser.php',
        'src/Transpiler/Target/AbstractCompilerVisitor.php',
        'src/Transpiler/Target/JavaScript/JavaScriptCompilerVisitor.php',
        'src/Transpiler/Target/Python/PythonCompilerVisitor.php',
        'src/Transpiler/Target/Python/PythonTarget.php',
    ];

    /**
     * Reading the engine's last error belongs to whoever ran the pattern:
     * the engine, the Lexer for its own tokenizing regexes, the benchmark.
     */
    private const LAST_ERROR_READERS = [
        'src/Lexer.php',
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
