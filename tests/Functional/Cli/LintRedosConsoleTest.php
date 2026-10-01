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

use PHPRegex\Cli\Command\HelpCommand;
use PHPRegex\Cli\Command\LintCommand;
use PHPRegex\Cli\Command\LintOutputRenderer;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPRegex\Linter\Config\LintArgumentParser;
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Linter\Config\LintExtractorFactory;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What "regex lint" says about a ReDoS finding outside the JSON report: the
 * help names the flag that turns the analysis on, the console shows the
 * attack and its replay under a failing verdict, the summary does not call a
 * ReDoS error an invalid pattern, and the XML reports carry the evidence
 * without a literal "\n".
 *
 * (a+)+$ on a…a! exhausts the backtrack limit at 19 pumps, JIT on and off
 * (PCRE2 10.49): in confirmed mode it is a replayed critical verdict, an
 * error.
 */
final class LintRedosConsoleTest extends TestCase
{
    use TemporaryProject;

    private const VULNERABLE_FILE = <<<'PHP'
        <?php

        preg_match('/(a+)+$/', $subject);

        PHP;

    // PCRE: "missing closing parenthesis at offset 9".
    private const INVALID_FILE = <<<'PHP'
        <?php

        preg_match('/(unclosed/', $subject);

        PHP;

    /**
     * ReDoS is off by default in lint: the flag that turns it on is listed,
     * with a description, wherever the lint options are.
     *
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideHelpScreens')]
    public function test_lint_help_lists_the_redos_flag(array $arguments): void
    {
        $output = $this->runHelp($arguments);

        $this->assertMatchesRegularExpression('/^\s*--redos {2,}\S.*$/m', $output);
    }

    /**
     * @return iterable<string, array{arguments: list<string>}>
     */
    public static function provideHelpScreens(): iterable
    {
        yield 'regex lint --help' => ['arguments' => ['lint']];
        yield 'regex --help' => ['arguments' => []];
    }

    /**
     * ReDoS is off unless "--redos" or regex.json's checks.redos.enabled
     * turns it on: "--no-redos" only undoes the latter, and every help line
     * that names it says so.
     *
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideHelpScreens')]
    public function test_lint_help_describes_no_redos_truthfully(array $arguments): void
    {
        $output = $this->runHelp($arguments);

        $this->assertNotFalse(preg_match_all('/^.*--no-redos\b.*$/m', $output, $lines));
        $this->assertNotSame([], $lines[0], $output);
        foreach ($lines[0] as $line) {
            $this->assertStringContainsString('regex.json', $line);
        }
    }

    /**
     * The semantics the help describes: off by default, on through
     * regex.json, off again through "--no-redos".
     *
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideRedosSwitches')]
    public function test_lint_redos_switches(?string $config, array $arguments, bool $reported): void
    {
        $files = ['src/a.php' => self::VULNERABLE_FILE];
        if (null !== $config) {
            $files['regex.json'] = $config;
        }
        $this->enterProject($files);

        [, $stdout] = $this->runLint(['src', ...$arguments, '--format=json', '--jobs=1']);

        $this->assertSame($reported, str_contains($stdout, '"regex.lint.redos"'), $stdout);
    }

    /**
     * @return iterable<string, array{config: ?string, arguments: list<string>, reported: bool}>
     */
    public static function provideRedosSwitches(): iterable
    {
        $enabled = '{"checks": {"redos": {"enabled": true}}}';

        yield 'default' => ['config' => null, 'arguments' => [], 'reported' => false];
        yield '--redos' => ['config' => null, 'arguments' => ['--redos'], 'reported' => true];
        yield 'regex.json enables it' => ['config' => $enabled, 'arguments' => [], 'reported' => true];
        yield 'regex.json enables it, --no-redos' => ['config' => $enabled, 'arguments' => ['--no-redos'], 'reported' => false];
    }

    /**
     * The other formats print the evidence of a failing verdict; the console
     * printed none of it under FAIL.
     */
    #[Test]
    public function test_lint_console_shows_the_evidence_of_a_redos_error(): void
    {
        $this->enterProject(['src/a.php' => self::VULNERABLE_FILE]);

        [$code, $stdout] = $this->runLint(['src', '--redos', '--redos-mode=confirmed', '--jobs=1']);

        $this->assertSame(1, $code, $stdout);
        $start = strpos($stdout, 'Exponential backtracking (proven)');
        $this->assertNotFalse($start, $stdout);
        $verdict = substr($stdout, $start);

        $this->assertStringContainsString('Attack: "a" x n . "!"', $verdict);
        $this->assertStringContainsString('Replayed on PCRE2 '.self::pcreRelease().': preg_match fails from length ', $verdict);
    }

    /**
     * The pattern compiles: a ReDoS error is not an invalid pattern.
     */
    #[Test]
    public function test_lint_summary_does_not_count_a_redos_error_as_an_invalid_pattern(): void
    {
        $this->enterProject(['src/a.php' => self::VULNERABLE_FILE]);

        [$code, $stdout] = $this->runLint(['src', '--redos', '--redos-mode=confirmed', '--jobs=1']);

        $this->assertSame(1, $code, $stdout);
        $summary = self::summaryLine($stdout);
        $this->assertStringStartsWith('FAIL ', $summary);
        $this->assertDoesNotMatchRegularExpression('/[1-9]\d* invalid patterns?/', $summary);
        $this->assertMatchesRegularExpression('/\b1\b[^,.]*\bReDoS\b/i', $summary);
    }

    /**
     * The label stays for what it names: a pattern PCRE refuses to compile.
     */
    #[Test]
    public function test_lint_summary_counts_a_pattern_pcre_rejects_as_invalid(): void
    {
        $this->assertFalse(@preg_match(self::invalidPattern(), ''));
        $this->enterProject(['src/a.php' => self::INVALID_FILE]);

        [$code, $stdout] = $this->runLint(['src', '--jobs=1']);

        $this->assertSame(1, $code, $stdout);
        $this->assertStringContainsString('1 invalid patterns', self::summaryLine($stdout));
    }

    /**
     * Every message of the XML reports joins its parts without the two
     * characters "\n", and the ReDoS message still carries the evidence.
     */
    #[Test]
    #[DataProvider('provideXmlFormats')]
    public function test_lint_xml_messages_have_no_literal_backslash_n(string $format, string $query): void
    {
        $this->enterProject(['src/a.php' => self::VULNERABLE_FILE]);

        [, $stdout] = $this->runLint(['src', '--redos', '--redos-mode=confirmed', '--jobs=1', '--format='.$format]);

        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML('' !== $stdout ? $stdout : '<empty/>'), $stdout);
        $nodes = (new \DOMXPath($document))->query($query);
        $this->assertNotFalse($nodes);

        $messages = [];
        foreach ($nodes as $node) {
            $messages[] = (string) $node->nodeValue;
        }
        $this->assertNotSame([], $messages, $stdout);

        foreach ($messages as $message) {
            $this->assertStringNotContainsString('\n', $message);
        }

        $redos = array_values(array_filter($messages, static fn (string $message): bool => str_contains($message, 'Exponential backtracking (proven)')));
        $this->assertCount(1, $redos, $stdout);
        $this->assertStringContainsString('Attack: "a" x n . "!"', $redos[0]);
        $this->assertStringContainsString('Replayed on PCRE2 '.self::pcreRelease().': preg_match fails from length ', $redos[0]);
    }

    /**
     * @return iterable<string, array{format: string, query: string}>
     */
    public static function provideXmlFormats(): iterable
    {
        yield 'checkstyle message attribute' => ['format' => 'checkstyle', 'query' => '//error/@message'];
        // JUnit keeps the headline in the attribute and the full text in the element.
        yield 'junit error text' => ['format' => 'junit', 'query' => '//error'];
    }

    /**
     * The pattern of INVALID_FILE, through a call so that static analysis
     * does not compile it.
     */
    private static function invalidPattern(): string
    {
        return '/(unclosed/';
    }

    private static function summaryLine(string $stdout): string
    {
        self::assertNotFalse(preg_match_all('/^\s*((?:FAIL|PASS)\b.*)$/m', $stdout, $lines));
        self::assertNotSame([], $lines[1], $stdout);

        return $lines[1][\count($lines[1]) - 1];
    }

    /**
     * @param list<string> $arguments
     */
    private function runHelp(array $arguments): string
    {
        $input = new Input('help', $arguments, new GlobalOptions(false, false, false, true, null, null), []);

        ob_start();

        try {
            (new HelpCommand())->run($input, new Output(false, false));
        } finally {
            $stdout = (string) ob_get_clean();
        }

        return $stdout;
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string}
     */
    private function runLint(array $args): array
    {
        $command = new LintCommand(
            new HelpCommand(),
            new LintConfigLoader(),
            new LintDefaultsBuilder(),
            new LintArgumentParser(),
            new LintExtractorFactory(),
            new LintOutputRenderer(),
        );
        $input = new Input('lint', $args, new GlobalOptions(false, false, false, true, null, null), []);

        ob_start();

        try {
            $exitCode = $command->run($input, new Output(false, false));
        } finally {
            $stdout = (string) ob_get_clean();
        }

        return [$exitCode, $stdout];
    }

    private static function pcreRelease(): string
    {
        return explode(' ', \PCRE_VERSION)[0];
    }
}
