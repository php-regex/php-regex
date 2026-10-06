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

use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The JSON every command prints, from real runs of the binary: one
 * document on stdout ending with one newline, snake_case keys, and exactly
 * the keys the contract writes down, object by object.
 */
final class JsonOutputContractTest extends TestCase
{
    use RunsRegexCli;
    use TemporaryProject;

    /**
     * A lint issue (a{1}), a ReDoS issue with its analysis ((a+)+$ under
     * --redos), an invalid pattern with its validation ((a), and an
     * optimization ([a-zA-Z0-9_]+ is \w+).
     */
    private const LINTED_FILE = <<<'PHP'
        <?php

        preg_match('/a{1}/', $s);
        preg_match('/(a+)+$/', $s);
        preg_match('/(a/', $s);
        preg_match('/[a-zA-Z0-9_]+/', $s);

        PHP;

    private const LINT = ['lint', 'src', '--format=json', '--jobs=1', '--redos'];

    private const REDOS = ['--input', 'ab', '--iterations', '1', '--warmup', '0', '--format=json'];

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideDocuments')]
    public function test_json_document_is_one_document_ending_with_one_newline(array $arguments, string $address): void
    {
        $this->enterLintedProject();

        [, $stdout] = $this->runRegex($arguments);

        JsonContract::decodeDocument($stdout);
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideDocuments')]
    public function test_json_document_keys_are_snake_case(array $arguments, string $address): void
    {
        $this->enterLintedProject();

        [, $stdout] = $this->runRegex($arguments);

        $document = json_decode($stdout, true);
        $this->assertIsArray($document, 'stdout is not JSON: '.$stdout);
        JsonContract::assertSnakeCaseKeys($document);
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideDocuments')]
    public function test_json_document_has_exactly_the_documented_keys(array $arguments, string $address): void
    {
        $this->enterLintedProject();

        [, $stdout] = $this->runRegex($arguments);

        $document = json_decode($stdout, true);
        $this->assertIsArray($document, 'stdout is not JSON: '.$stdout);
        JsonContract::assertShape($address, $document);
    }

    /**
     * @return iterable<string, array{arguments: list<string>, address: string}>
     */
    public static function provideDocuments(): iterable
    {
        yield 'analyze, a valid pattern' => ['arguments' => ['analyze', '/a+b/', '--format=json'], 'address' => 'analyze'];
        yield 'analyze, a syntax error' => ['arguments' => ['analyze', '/(a/', '--format=json'], 'address' => 'analyze'];
        yield 'analyze, a semantic error' => ['arguments' => ['analyze', '/(?<=a+)b/', '--format=json'], 'address' => 'analyze'];
        yield 'analyze, a confirmed ReDoS' => ['arguments' => ['analyze', '/(a+)+$/', '--format=json', '--redos-mode=confirmed'], 'address' => 'analyze'];
        yield 'analyze, --json' => ['arguments' => ['analyze', '/a+b/', '--json'], 'address' => 'analyze'];
        yield 'analyze, quiet' => ['arguments' => ['--quiet', 'analyze', '/a+b/', '--format=json'], 'address' => 'analyze'];
        yield 'debug, a valid pattern' => ['arguments' => ['debug', '/a+b/', '--format=json'], 'address' => 'debug'];
        yield 'debug, a syntax error' => ['arguments' => ['debug', '/(a/', '--format=json'], 'address' => 'debug'];
        yield 'debug, a semantic error' => ['arguments' => ['debug', '/(?<=a+)b/', '--format=json'], 'address' => 'debug'];
        yield 'debug, a confirmed ReDoS' => ['arguments' => ['debug', '/(a+)+$/', '--format=json', '--redos-mode=confirmed'], 'address' => 'debug'];
        yield 'debug, quiet' => ['arguments' => ['--quiet', 'debug', '/a+b/', '--format=json'], 'address' => 'debug'];
        yield 'redos, one pattern' => ['arguments' => ['redos', '/a+b/', ...self::REDOS], 'address' => 'redos'];
        yield 'redos, against --safe' => ['arguments' => ['redos', '/(a+)+$/', '--safe', '/a+$/', ...self::REDOS], 'address' => 'redos'];
        yield 'redos, quiet' => ['arguments' => ['--quiet', 'redos', '/a+b/', ...self::REDOS], 'address' => 'redos'];
        yield 'transpile' => ['arguments' => ['transpile', '/a+b/i', '--format=json'], 'address' => 'transpile'];
        yield 'transpile, quiet' => ['arguments' => ['--quiet', 'transpile', '/a+b/i', '--format=json'], 'address' => 'transpile'];
        yield 'lint' => ['arguments' => self::LINT, 'address' => 'lint'];
        yield 'lint, confirmed ReDoS' => ['arguments' => [...self::LINT, '--redos-mode=confirmed'], 'address' => 'lint'];
        yield 'lint, with a baseline' => ['arguments' => [...self::LINT, '--baseline=baseline.json'], 'address' => 'lint'];
        yield 'lint, quiet' => ['arguments' => ['--quiet', ...self::LINT], 'address' => 'lint'];
        yield 'lint, nothing to report' => ['arguments' => ['lint', 'empty', '--format=json', '--jobs=1'], 'address' => 'lint'];
        yield 'error envelope, a usage error' => ['arguments' => ['analyze', '/a/', '--bogus', '--json'], 'address' => 'error'];
        yield 'error envelope, an invalid pattern' => ['arguments' => ['transpile', '/(a/', '--format=json'], 'address' => 'error'];
    }

    /**
     * transpile reads --format as a separate argument as well, in any case
     * and before the pattern too: the value is consumed, never read as the
     * pattern.
     *
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideTranspileFormatSpellings')]
    public function test_transpile_reads_the_format_given_as_a_separate_argument(array $arguments): void
    {
        $this->enterProject();

        [$exitCode, $stdout] = $this->runRegex($arguments);

        $this->assertSame(0, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('transpile', $document);
        $this->assertSame('/a+b/i', $document['source'] ?? null);
        $this->assertSame('new RegExp("a+b", "i")', $document['constructor'] ?? null);
    }

    /**
     * @return iterable<string, array{arguments: list<string>}>
     */
    public static function provideTranspileFormatSpellings(): iterable
    {
        yield 'after the pattern' => ['arguments' => ['transpile', '/a+b/i', '--format', 'json']];
        yield 'before the pattern' => ['arguments' => ['transpile', '--format', 'json', '/a+b/i']];
        yield 'in capitals' => ['arguments' => ['transpile', '/a+b/i', '--format', 'JSON']];
    }

    #[Test]
    public function test_analyze_reports_a_valid_pattern(): void
    {
        $this->enterLintedProject();

        [$exitCode, $stdout] = $this->runRegex(['analyze', '/a+b/', '--format=json']);

        $this->assertSame(0, $exitCode);
        $document = JsonContract::decodeDocument($stdout);
        $this->assertSame(['ok' => true], $document['parse'] ?? null);
        $validation = $document['validation'] ?? null;
        $this->assertIsArray($validation);
        $this->assertTrue($validation['is_valid'] ?? null);
        foreach (['error', 'error_code', 'offset'] as $key) {
            $this->assertArrayHasKey($key, $validation);
            $this->assertNull($validation[$key], $key);
        }
        $this->assertIsArray($document['redos'] ?? null);
        $this->assertIsString($document['explain'] ?? null);
    }

    /**
     * The offsets are PCRE's own: "missing closing parenthesis at offset 2"
     * and "length of lookbehind assertion is not limited at offset 0".
     */
    #[Test]
    #[DataProvider('provideInvalidPatterns')]
    public function test_analyze_puts_an_invalid_pattern_in_its_payload(string $pattern, int $offset, string $errorCode, string $category): void
    {
        $this->assertFalse(@preg_match($pattern, ''));
        $this->enterLintedProject();

        [$exitCode, $stdout] = $this->runRegex(['analyze', $pattern, '--format=json']);

        $this->assertSame(1, $exitCode);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('analyze', $document);
        $this->assertSame($pattern, $document['pattern']);
        $this->assertSame(['ok' => false], $document['parse']);
        $this->assertInvalidValidation($document['validation'], $offset, $errorCode, $category);
        $this->assertNull($document['redos']);
        $this->assertNull($document['explain']);
    }

    #[Test]
    #[DataProvider('provideInvalidPatterns')]
    public function test_debug_puts_an_invalid_pattern_in_its_payload(string $pattern, int $offset, string $errorCode, string $category): void
    {
        $this->enterLintedProject();

        [, $stdout] = $this->runRegex(['debug', $pattern, '--format=json']);

        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('debug', $document);
        $this->assertSame($pattern, $document['pattern']);
        $this->assertInvalidValidation($document['validation'], $offset, $errorCode, $category);
        $this->assertNull($document['analysis']);
    }

    /**
     * The payload of an invalid pattern still echoes the input given, and
     * names its source only when there is one.
     *
     * @param list<string>                                   $options
     * @param array{value: string|null, source: string|null} $input
     */
    #[Test]
    #[DataProvider('provideDebugInputs')]
    public function test_debug_invalid_pattern_payload_echoes_the_input(array $options, array $input): void
    {
        $this->enterLintedProject();

        [, $stdout] = $this->runRegex(['debug', '/(a/', '--format=json', ...$options]);

        $this->assertSame($input, JsonContract::decodeDocument($stdout)['input'] ?? null);
    }

    /**
     * @return iterable<string, array{options: list<string>, input: array{value: string|null, source: string|null}}>
     */
    public static function provideDebugInputs(): iterable
    {
        yield 'no input' => ['options' => [], 'input' => ['value' => null, 'source' => null]];
        yield 'an input given' => ['options' => ['--input', 'aaa'], 'input' => ['value' => 'aaa', 'source' => 'user']];
    }

    #[Test]
    public function test_debug_exits_1_on_a_syntax_error_as_json(): void
    {
        $this->enterLintedProject();

        [$exitCode] = $this->runRegex(['debug', '/(a/', '--format=json']);

        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function test_debug_reports_a_valid_pattern_with_its_validation(): void
    {
        $this->enterLintedProject();

        [$exitCode, $stdout] = $this->runRegex(['debug', '/a+b/', '--format=json']);

        $this->assertSame(0, $exitCode);
        $document = JsonContract::decodeDocument($stdout);
        $validation = $document['validation'] ?? null;
        $this->assertIsArray($validation);
        $this->assertTrue($validation['is_valid'] ?? null);
        $this->assertIsArray($document['analysis'] ?? null);
    }

    /**
     * @param list<string> $options
     */
    #[Test]
    #[DataProvider('provideCommandsActingOnAPattern')]
    public function test_command_acting_on_an_invalid_pattern_stops_with_the_envelope(string $command, array $options, string $pattern, int $offset, string $errorCode, string $category): void
    {
        $this->enterLintedProject();

        [$exitCode, $stdout] = $this->runRegex([$command, $pattern, ...$options]);

        $this->assertSame(1, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('error', $document);
        $keys = array_keys($document);
        sort($keys);
        $this->assertSame(['error', 'stage', 'validation'], $keys);
        $this->assertSame('pattern', $document['stage']);
        $this->assertIsString($document['error']);
        $this->assertNotSame('', $document['error']);
        $this->assertInvalidValidation($document['validation'], $offset, $errorCode, $category);
        // The envelope's message is the validation's own.
        $this->assertSame(JsonContract::asArray($document['validation'])['error'] ?? null, $document['error']);
    }

    /**
     * @return iterable<string, array{command: string, options: list<string>, pattern: string, offset: int, errorCode: string, category: string}>
     */
    public static function provideCommandsActingOnAPattern(): iterable
    {
        foreach (self::provideInvalidPatterns() as $name => $invalid) {
            yield 'transpile, '.$name => ['command' => 'transpile', 'options' => ['--format=json'], ...$invalid];
            yield 'redos, '.$name => ['command' => 'redos', 'options' => self::REDOS, ...$invalid];
        }
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int, errorCode: string, category: string}>
     */
    public static function provideInvalidPatterns(): iterable
    {
        yield 'a syntax error' => ['pattern' => '/(a/', 'offset' => 2, 'errorCode' => 'regex.group.unclosed', 'category' => 'syntax'];
        yield 'a semantic error' => ['pattern' => '/(?<=a+)b/', 'offset' => 0, 'errorCode' => 'regex.lookbehind.unbounded', 'category' => 'semantic'];
    }

    #[Test]
    public function test_lint_issue_carries_every_key_and_an_issue_id(): void
    {
        $this->enterLintedProject();

        [$exitCode, $stdout] = $this->runRegex(self::LINT);

        $this->assertSame(1, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('lint', $document);
        $this->assertSame(
            ['errors' => 1, 'warnings' => 3, 'optimizations' => 2, 'redos_errors' => 0, 'infos' => 1, 'lint_errors' => 0],
            $document['stats'],
        );

        $byPattern = [];
        foreach (JsonContract::asArray($document['results']) as $result) {
            $this->assertIsArray($result);
            $this->assertIsString($result['pattern']);
            $byPattern[$result['pattern']] = $result;
            foreach (JsonContract::asArray($result['issues']) as $issue) {
                $this->assertIsArray($issue);
                $this->assertIsString($issue['issue_id']);
                $this->assertNotSame('', $issue['issue_id']);
                $this->assertContains($issue['severity'], ['error', 'warning', 'info']);
                $this->assertSame('src/a.php', $issue['file']);
            }
        }

        // The invalid pattern: its ErrorCode is its issue id.
        $invalid = self::onlyIssue($byPattern['/(a/']['issues'], 'regex.group.unclosed');
        $this->assertSame('error', $invalid['severity']);
        $this->assertSame(2, $invalid['position']);
        $this->assertInvalidValidation($invalid['validation'], 2, 'regex.group.unclosed', 'syntax');
        $this->assertNull($invalid['analysis']);

        // A lint rule: no validation, no analysis.
        $lint = self::onlyIssue($byPattern['/a{1}/']['issues'], 'regex.lint.quantifier.useless');
        $this->assertSame('warning', $lint['severity']);
        $this->assertNull($lint['validation']);
        $this->assertNull($lint['analysis']);
        $this->assertIsString($lint['hint']);

        // The ReDoS verdict carries its analysis.
        $redos = self::onlyIssue($byPattern['/(a+)+$/']['issues'], 'regex.lint.redos');
        $this->assertIsArray($redos['analysis']);
        $this->assertSame('critical', $redos['analysis']['severity']);
        $this->assertNull($redos['validation']);

        // The optimization.
        $optimizations = JsonContract::asArray($byPattern['/[a-zA-Z0-9_]+/']['optimizations']);
        $this->assertCount(1, $optimizations);
        $optimization = JsonContract::asArray($optimizations[0]);
        $this->assertSame(['original' => '/[a-zA-Z0-9_]+/', 'optimized' => '/\w+/', 'changes' => ['Optimized pattern.']], $optimization['optimization']);
        $this->assertSame(10, $optimization['savings']);
        $this->assertSame([], $byPattern['/[a-zA-Z0-9_]+/']['issues']);
    }

    #[Test]
    public function test_lint_with_nothing_to_report_prints_an_empty_result_list(): void
    {
        $this->enterLintedProject();

        [$exitCode, $stdout] = $this->runRegex(['lint', 'empty', '--format=json', '--jobs=1']);

        $this->assertSame(0, $exitCode);
        $document = JsonContract::decodeDocument($stdout);
        $this->assertSame([], $document['results']);
        $this->assertSame(['errors' => 0, 'warnings' => 0, 'optimizations' => 0, 'redos_errors' => 0, 'infos' => 0, 'lint_errors' => 0], $document['stats']);
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideRuntimes')]
    public function test_runtime_values_have_their_documented_types(array $arguments): void
    {
        $this->enterLintedProject();

        [, $stdout] = $this->runRegex($arguments);

        $document = JsonContract::decodeDocument($stdout);
        $runtime = $document['runtime'] ?? null;
        $this->assertIsArray($runtime);
        $this->assertIsString($runtime['version'] ?? null);
        $this->assertIsBool($runtime['jit'] ?? null);
        $this->assertIsInt($runtime['backtrack_limit'] ?? null);
        $this->assertIsInt($runtime['recursion_limit'] ?? null);
    }

    /**
     * @return iterable<string, array{arguments: list<string>}>
     */
    public static function provideRuntimes(): iterable
    {
        yield 'analyze' => ['arguments' => ['analyze', '/a+b/', '--format=json']];
        yield 'debug' => ['arguments' => ['debug', '/a+b/', '--format=json']];
        yield 'redos' => ['arguments' => ['redos', '/a+b/', ...self::REDOS]];
    }

    /**
     * The analyze command reports the process's own pcre.jit setting.
     */
    #[Test]
    public function test_runtime_jit_is_the_pcre_jit_setting(): void
    {
        $this->enterLintedProject();

        [, $stdout] = $this->runRegex(['analyze', '/a+b/', '--format=json']);

        $document = JsonContract::decodeDocument($stdout);
        $this->assertSame('1' === \ini_get('pcre.jit'), JsonContract::asArray($document['runtime'] ?? null)['jit'] ?? null);
    }

    /**
     * The confirmation always runs the interpreter: its pcre.jit setting
     * is off, reported as false.
     *
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideConfirmations')]
    public function test_confirmation_jit_setting_is_a_bool(array $arguments, string $path): void
    {
        $this->enterLintedProject();

        [, $stdout] = $this->runRegex($arguments);

        $document = JsonContract::decodeDocument($stdout);
        $analysis = 'lint' === $path ? self::redosAnalysisOfLint($document) : ($document[$path] ?? null);
        $this->assertIsArray($analysis, $stdout);
        $confirmation = $analysis['confirmation'] ?? null;
        $this->assertIsArray($confirmation, $stdout);
        $this->assertFalse($confirmation['jit_setting'] ?? null);
    }

    /**
     * @return iterable<string, array{arguments: list<string>, path: string}>
     */
    public static function provideConfirmations(): iterable
    {
        yield 'analyze' => ['arguments' => ['analyze', '/(a+)+$/', '--format=json', '--redos-mode=confirmed'], 'path' => 'redos'];
        yield 'debug' => ['arguments' => ['debug', '/(a+)+$/', '--format=json', '--redos-mode=confirmed'], 'path' => 'analysis'];
        yield 'lint' => ['arguments' => [...self::LINT, '--redos-mode=confirmed'], 'path' => 'lint'];
    }

    /**
     * Unicode is printed as itself, not as \u escapes.
     *
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideUnicodePatterns')]
    public function test_json_prints_unicode_unescaped(array $arguments, string $key): void
    {
        $this->enterLintedProject();

        [, $stdout] = $this->runRegex($arguments);

        JsonContract::decodeDocument($stdout);
        $this->assertStringContainsString('"'.$key.'": "/é+/u"', $stdout);
    }

    /**
     * @return iterable<string, array{arguments: list<string>, key: string}>
     */
    public static function provideUnicodePatterns(): iterable
    {
        yield 'analyze' => ['arguments' => ['analyze', '/é+/u', '--format=json'], 'key' => 'pattern'];
        yield 'debug' => ['arguments' => ['debug', '/é+/u', '--format=json'], 'key' => 'pattern'];
        yield 'redos' => ['arguments' => ['redos', '/é+/u', '--input', 'é', '--iterations', '1', '--warmup', '0', '--format=json'], 'key' => 'pattern'];
        yield 'transpile' => ['arguments' => ['transpile', '/é+/u', '--format=json'], 'key' => 'source'];
    }

    /**
     * A byte that is not UTF-8 is a valid pattern byte without the u flag:
     * every command still prints JSON, the pattern in the display form the
     * lint report already uses.
     *
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideInvalidBytes')]
    public function test_pattern_with_an_invalid_utf8_byte_still_prints_json(array $arguments, string $key, string $expected): void
    {
        $this->assertSame(1, preg_match("/a\xFF/", "a\xFF"));
        // A lint report lists the patterns that have an issue: this one nests quantifiers.
        $this->enterProject(['src/a.php' => "<?php\n\npreg_match('/(a\xFF+)+\$/', \$s);\n"]);

        [, $stdout] = $this->runRegex($arguments);

        $document = JsonContract::decodeDocument($stdout);
        $value = 'lint' === $key
            ? (JsonContract::asArray(JsonContract::asArray($document['results'] ?? null)[0] ?? null)['pattern'] ?? null)
            : ($document[$key] ?? null);
        // Valid UTF-8 is written as itself; each invalid byte as the text \xNN.
        $this->assertSame($expected, $value, $stdout);
    }

    /**
     * @return iterable<string, array{arguments: list<string>, key: string, expected: string}>
     */
    public static function provideInvalidBytes(): iterable
    {
        yield 'analyze' => ['arguments' => ['analyze', "/a\xFF/", '--format=json'], 'key' => 'pattern', 'expected' => '/a\xFF/'];
        yield 'debug' => ['arguments' => ['debug', "/a\xFF/", '--format=json'], 'key' => 'pattern', 'expected' => '/a\xFF/'];
        yield 'redos' => ['arguments' => ['redos', "/a\xFF/", ...self::REDOS], 'key' => 'pattern', 'expected' => '/a\xFF/'];
        yield 'transpile' => ['arguments' => ['transpile', "/a\xFF/", '--format=json'], 'key' => 'source', 'expected' => '/a\xFF/'];
        yield 'lint' => ['arguments' => ['lint', 'src', '--format=json', '--jobs=1'], 'key' => 'lint', 'expected' => '/(a\xFF+)+$/'];
    }

    private function enterLintedProject(): void
    {
        $this->enterProject([
            'src/a.php' => self::LINTED_FILE,
            'empty/b.php' => "<?php\n",
            'baseline.json' => "[]\n",
        ]);
    }

    private function assertInvalidValidation(mixed $validation, int $offset, string $errorCode, string $category): void
    {
        $this->assertIsArray($validation);
        $this->assertFalse($validation['is_valid'] ?? null);
        $this->assertIsString($validation['error'] ?? null);
        $this->assertSame($offset, $validation['offset'] ?? null);
        $this->assertSame($errorCode, $validation['error_code'] ?? null);
        $this->assertSame($category, $validation['category'] ?? null);
        $this->assertIsString($validation['caret_snippet'] ?? null);
    }

    /**
     * @return array<mixed>
     */
    private static function onlyIssue(mixed $issues, string $issueId): array
    {
        self::assertIsArray($issues);
        $found = array_values(array_filter($issues, static fn (mixed $issue): bool => \is_array($issue) && $issueId === ($issue['issue_id'] ?? null)));
        self::assertCount(1, $found, 'Expected one '.$issueId.' issue in '.json_encode($issues));
        self::assertIsArray($found[0]);

        return $found[0];
    }

    /**
     * @param array<mixed> $document
     */
    private static function redosAnalysisOfLint(array $document): mixed
    {
        foreach ((array) ($document['results'] ?? []) as $result) {
            foreach ((array) (\is_array($result) ? ($result['issues'] ?? []) : []) as $issue) {
                if (\is_array($issue) && 'regex.lint.redos' === ($issue['issue_id'] ?? null)) {
                    return $issue['analysis'] ?? null;
                }
            }
        }

        return null;
    }
}
