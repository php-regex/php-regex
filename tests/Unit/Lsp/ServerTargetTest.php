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

namespace PHPRegex\Tests\Unit\Lsp;

use PHPRegex\LanguageServer\Protocol\Transport;
use PHPRegex\LanguageServer\Server;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\PcreRule;
use PHPRegex\Tests\Support\RangeFixtureReadings;
use PHPRegex\Tests\TestUtils\FileUri;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The language server judges a workspace for its target, resolved once at
 * "initialize": initializationOptions.phpVersion / pcreVersion, else
 * regex.json at the root folder, else composer.json there, else the running
 * PHP; the target and where it came from are logged once through
 * window/logMessage, and the diagnostics judge for it.
 */
final class ServerTargetTest extends TestCase
{
    use InstalledLocales;
    use PcreRule;
    use RangeFixtureReadings;

    /**
     * "(?a)", ASCII-only classes, arrived in PCRE2 10.43
     * (PcreFeature::AsciiOptions): PHP 8.2's 10.40 refuses it, this PHP's
     * PCRE2 (10.49) compiles it — preg_match() returns 0, not false.
     */
    private const NEWER_ENGINE_ONLY = '/(?a)x/';

    private const DOCUMENT_URI = 'file:///workspace/src/Pattern.php';

    /**
     * Compiles on PHP 8.4 (preg_match() returns 1 on 8.4.27, PCRE2 10.49);
     * PHP 8.5 compiles without PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK, and PCRE2
     * refuses it there: `regex lint` reports it under ">=8.2".
     */
    private const KEEP_IN_LOOKBEHIND = '/(?<=a\\Kb)c/';

    /**
     * Text before PCRE2 10.43, a quantifier from it: preg_match() of
     * "/^a{,3}$/" matches "aa" on 10.49, and not the text "a{,3}".
     */
    private const QUANTIFIER_WITHOUT_MIN = '/a{,3}/';

    /**
     * @var resource
     */
    private $output;

    private string $root;

    protected function setUp(): void
    {
        $output = fopen('php://memory', 'r+');
        if (false === $output) {
            self::fail('Unable to open an in-memory stream.');
        }

        $this->output = $output;

        $this->root = sys_get_temp_dir().'/regex-parser-lsp-target-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        fclose($this->output);

        foreach (glob($this->root.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    #[Test]
    public function test_initialization_options_set_the_target(): void
    {
        $this->requiresBundledPcre2(80200, __FUNCTION__);
        $this->serve(['rootUri' => $this->rootUri(), 'initializationOptions' => ['phpVersion' => '8.2']]);

        $this->assertNotSame([], $this->diagnostics(), 'PHP 8.2 bundles PCRE2 10.40, which refuses "(?a)".');
        $log = $this->targetLog();
        $this->assertStringContainsString('8.2', $log);
        $this->assertStringContainsString('10.40', $log);
        $this->assertStringContainsString('initializationOptions', $log);
    }

    #[Test]
    public function test_initialization_options_set_the_pcre_release_alone(): void
    {
        $this->serve(['rootUri' => $this->rootUri(), 'initializationOptions' => ['pcreVersion' => '10.40']]);

        $this->assertNotSame([], $this->diagnostics());
        $this->assertStringContainsString('10.40', $this->targetLog());
    }

    #[Test]
    public function test_initialization_options_win_over_regex_json(): void
    {
        file_put_contents($this->root.'/regex.json', (string) json_encode(['phpVersion' => '8.4', 'pcreVersion' => '10.44']));

        $this->serve(['rootUri' => $this->rootUri(), 'initializationOptions' => ['phpVersion' => '8.2']]);

        $this->assertStringContainsString('8.2', $this->targetLog());
        $this->assertStringNotContainsString('8.4', $this->targetLog());
    }

    #[Test]
    public function test_regex_json_at_the_root_sets_the_target(): void
    {
        $this->requiresBundledPcre2(80200, __FUNCTION__);
        file_put_contents($this->root.'/regex.json', (string) json_encode(['phpVersion' => '8.2']));

        $this->serve(['rootUri' => $this->rootUri()]);

        $this->assertNotSame([], $this->diagnostics());
        $log = $this->targetLog();
        $this->assertStringContainsString('8.2', $log);
        $this->assertStringContainsString('regex.json', $log);
    }

    #[Test]
    public function test_regex_json_wins_over_composer_json(): void
    {
        $this->requiresBundledPcre2(80200, __FUNCTION__);
        file_put_contents($this->root.'/regex.json', (string) json_encode(['phpVersion' => '8.2']));
        file_put_contents($this->root.'/composer.json', (string) json_encode(['require' => ['php' => '>=8.4']]));

        $this->serve(['rootUri' => $this->rootUri()]);

        $this->assertStringContainsString('regex.json', $this->targetLog());
        $this->assertNotSame([], $this->diagnostics());
    }

    #[Test]
    public function test_composer_json_of_the_first_workspace_folder_sets_the_target(): void
    {
        $this->requiresBundledPcre2(80200, __FUNCTION__);
        file_put_contents($this->root.'/composer.json', (string) json_encode(['require' => ['php' => '^8.2']]));

        $this->serve(['workspaceFolders' => [
            ['uri' => $this->rootUri(), 'name' => 'app'],
            ['uri' => 'file:///nowhere', 'name' => 'other'],
        ]]);

        $this->assertNotSame([], $this->diagnostics());
        $log = $this->targetLog();
        $this->assertStringContainsString('8.2', $log);
        $this->assertStringContainsString('composer.json', $log);
    }

    #[Test]
    public function test_an_empty_root_judges_for_the_running_php(): void
    {
        $this->serve(['rootUri' => $this->rootUri()]);

        $runningAccepts = Regex::create()->validate(self::NEWER_ENGINE_ONLY)->isValid;
        $this->assertSame($runningAccepts, [] === $this->diagnostics());
        $this->assertStringContainsString('running PHP', $this->targetLog());
    }

    /**
     * PHP 7.2 and earlier ship PCRE1, which no verdict of the library
     * holds for: the server shows why and judges no pattern, rather than
     * judge them for another PHP.
     */
    #[Test]
    public function test_a_target_on_pcre1_is_shown_and_judges_no_pattern(): void
    {
        file_put_contents($this->root.'/composer.json', (string) json_encode(['require' => ['php' => '>=5.6 <7.3']]));

        $this->serve(['rootUri' => $this->rootUri()], hover: true);

        $message = 'composer.json require.php allows PHP 5.6: PHP 7.2 and earlier ship PCRE1, which this library does not judge. Name a PHP version of 7.3 or later to judge for. No pattern is judged.';
        $this->assertSame([['type' => 1, 'message' => $message]], $this->notifications('window/showMessage'));
        $this->assertContains(['type' => 1, 'message' => $message], $this->notifications('window/logMessage'));
        $this->assertSame([], $this->notifications('textDocument/publishDiagnostics'));
        $this->assertSame([], $this->targetLogs());
        $this->assertSame(['jsonrpc' => '2.0', 'id' => 2, 'result' => null], $this->response(2));
    }

    /**
     * A require.php that reaches PCRE2 is judged from the lowest PHP of 7.3
     * or later it allows; the versions below 7.3 are logged as not judged.
     */
    #[Test]
    public function test_a_range_from_below_7_3_is_judged_from_its_lowest_php_on_pcre2(): void
    {
        file_put_contents($this->root.'/composer.json', (string) json_encode(['require' => ['php' => '>=5.6']]));

        $this->serve(['rootUri' => $this->rootUri()]);

        $this->assertSame([], $this->notifications('window/showMessage'));
        $this->assertContains(
            ['type' => 3, 'message' => 'composer.json require.php allows PHP 5.6, and PHP 7.2 and earlier ship PCRE1, which this library does not judge: judging from PHP 7.3, the lowest version it allows on PCRE2.'],
            $this->notifications('window/logMessage'),
        );
        $this->assertSame(['Target: PHP 7.3, PCRE2 10.32 (composer.json require.php)'], array_values(array_filter(
            $this->targetLogs(),
            static fn (string $log): bool => str_starts_with($log, 'Target: '),
        )));
        $this->assertNotSame([], $this->diagnostics());
    }

    /**
     * An initializationOptions.phpVersion the server cannot read is passed
     * over with a warning, and the next source judges: "initialize" gets
     * its one answer, never a second one for a failure while reading it.
     */
    #[Test]
    public function test_a_php_version_past_every_integer_is_passed_over_and_initialize_answered_once(): void
    {
        file_put_contents($this->root.'/regex.json', (string) json_encode(['phpVersion' => '8.4']));

        $this->serve(['rootUri' => $this->rootUri(), 'initializationOptions' => ['phpVersion' => '>=99999999999999999999.1']]);

        $answers = $this->responses(1);
        $this->assertCount(1, $answers, (string) json_encode($answers));
        $this->assertArrayHasKey('result', $answers[0]);
        $this->assertArrayNotHasKey('error', $answers[0]);
        $this->assertContains(
            ['type' => 2, 'message' => 'initializationOptions.phpVersion ">=99999999999999999999.1" is not a version: ignored.'],
            $this->notifications('window/logMessage'),
        );
        $this->assertStringEndsWith('(regex.json)', $this->targetLog());
    }

    #[Test]
    public function test_the_target_is_logged_once(): void
    {
        file_put_contents($this->root.'/regex.json', (string) json_encode(['phpVersion' => '8.2']));

        // Two documents opened: the target is resolved at initialize only.
        $this->serve(['rootUri' => $this->rootUri()], extraDocuments: 1);

        $this->assertCount(1, $this->targetLogs());
    }

    #[Test]
    public function test_the_regex_the_server_was_started_with_is_logged_with_its_patch(): void
    {
        // One label for the PHP judged, wherever the target comes from:
        // major.minor, and the patch when it is not 0.
        $this->serve(['rootUri' => $this->rootUri()], regex: Regex::create(['php_version' => '8.4.25', 'pcre_version' => '10.44']));

        $this->assertSame(['Target: PHP 8.4.25, PCRE2 10.44 (the Regex the server was started with)'], $this->targetLogs());
    }

    #[Test]
    public function test_the_regex_the_server_was_started_with_is_logged_without_a_zero_patch(): void
    {
        $this->serve(['rootUri' => $this->rootUri()], regex: Regex::create(['php_version' => '8.2', 'pcre_version' => '10.40']));

        $this->assertSame(['Target: PHP 8.2, PCRE2 10.40 (the Regex the server was started with)'], $this->targetLogs());
        $this->assertNotSame([], $this->diagnostics(), 'PCRE2 10.40 refuses "(?a)".');
    }

    #[Test]
    public function test_the_regex_the_server_was_started_with_is_logged_with_its_locale(): void
    {
        $this->requiresLocale('fr_FR.ISO8859-1', 'The locale of the Regex the server was started with');
        $this->serve(['rootUri' => $this->rootUri()], regex: Regex::create(['php_version' => '8.2', 'pcre_version' => '10.40', 'locale' => 'fr_FR.ISO8859-1']));

        $this->assertSame(['Target: PHP 8.2, PCRE2 10.40, locale fr_FR.ISO8859-1 (the Regex the server was started with)'], $this->targetLogs());
    }

    #[Test]
    public function test_the_target_is_logged_with_its_locale(): void
    {
        $this->requiresLocale('fr_FR.ISO8859-1', 'The locale of the target logged');
        file_put_contents($this->root.'/regex.json', (string) json_encode(['phpVersion' => '8.2', 'locale' => 'fr_FR.ISO8859-1']));

        $this->serve(['rootUri' => $this->rootUri()]);

        $this->assertSame(['Target: PHP 8.2, PCRE2 '.self::judgedPcre(80200).', locale fr_FR.ISO8859-1 (regex.json)'], $this->targetLogs());
    }

    /**
     * The server judges every version of the range, as `regex lint` does:
     * a pattern the floor compiles and a later PHP refuses is an error,
     * with lint's words, whatever named the range.
     *
     * @param array<string, mixed>  $initializeParams
     * @param array<string, string> $files
     */
    #[Test]
    #[DataProvider('provideRangeSources')]
    public function test_a_pattern_a_later_php_of_the_range_refuses_is_an_error(array $initializeParams, array $files): void
    {
        self::assertEngineKeepInLookbehind();

        foreach ($files as $name => $contents) {
            file_put_contents($this->root.'/'.$name, $contents);
        }

        $this->serve(['rootUri' => $this->rootUri()] + $initializeParams, pattern: self::KEEP_IN_LOOKBEHIND);

        $refusals = array_values(array_filter($this->diagnostics(), static fn (mixed $diagnostic): bool => \is_array($diagnostic) && 'regex.keep.in_lookaround' === ($diagnostic['code'] ?? null)));
        $this->assertCount(1, $refusals, (string) json_encode($this->diagnostics()));
        $this->assertIsArray($refusals[0]);
        $this->assertSame(1, $refusals[0]['severity'] ?? null);
        $this->assertIsString($refusals[0]['message'] ?? null);
        $this->assertStringStartsWith('On PHP 8.5 and later: \\K is not allowed in a lookaround from PHP 8.5', $refusals[0]['message']);
    }

    /**
     * @return iterable<string, array{initializeParams: array<string, mixed>, files: array<string, string>}>
     */
    public static function provideRangeSources(): iterable
    {
        yield 'composer.json require.php' => ['initializeParams' => [], 'files' => ['composer.json' => '{"require": {"php": ">=8.2"}}']];
        yield 'a regex.json constraint' => ['initializeParams' => [], 'files' => ['regex.json' => '{"phpVersion": "^8.2"}']];
        yield 'a regex.json object' => ['initializeParams' => [], 'files' => ['regex.json' => '{"phpVersion": {"min": 80200, "max": 80699}}']];
        yield 'an initializationOptions constraint' => ['initializeParams' => ['initializationOptions' => ['phpVersion' => '>=8.2']], 'files' => []];
        yield 'an initializationOptions object' => ['initializeParams' => ['initializationOptions' => ['phpVersion' => ['min' => '8.2', 'max' => '8.6']]], 'files' => []];
    }

    /**
     * A pattern a later PHP of the range reads otherwise is a warning, with
     * lint's words; the PCRE2 named is the one that PHP is judged with.
     *
     * @param array<string, string> $files
     */
    #[Test]
    #[DataProvider('provideMeaningChangeSources')]
    public function test_a_pattern_a_later_php_of_the_range_parses_differently_is_a_warning(array $files, ?int $first): void
    {
        self::assertEngineReadsQuantifierWithoutMin();

        foreach ($files as $name => $contents) {
            file_put_contents($this->root.'/'.$name, $contents);
        }

        $this->serve(['rootUri' => $this->rootUri()], pattern: self::QUANTIFIER_WITHOUT_MIN);

        $changes = array_values(array_filter($this->diagnostics(), static fn (mixed $diagnostic): bool => \is_array($diagnostic) && 'regex.lint.compat.meaningChanges' === ($diagnostic['code'] ?? null)));
        $expected = null === $first ? [] : [[2, \sprintf('From PHP %s (PCRE2 %s) the pattern parses differently: the same text means something else there.', ProjectTarget::phpLabel($first), self::judgedPcre($first))]];
        $this->assertSame($expected, array_map(static fn (mixed $diagnostic): array => \is_array($diagnostic) ? [$diagnostic['severity'] ?? null, $diagnostic['message'] ?? null] : [], $changes));
    }

    /**
     * @return iterable<string, array{files: array<string, string>, first: int|null}>
     */
    public static function provideMeaningChangeSources(): iterable
    {
        $toTheEnd = [80100, 80200, 80300, 80400, 80425, 80500, 80510];

        yield 'composer.json require.php' => ['files' => ['composer.json' => '{"require": {"php": ">=8.1"}}'], 'first' => self::quantifierReadFrom($toTheEnd)];
        yield 'a regex.json constraint' => ['files' => ['regex.json' => '{"phpVersion": ">=8.1"}'], 'first' => self::quantifierReadFrom($toTheEnd)];
        yield 'a regex.json object to 8.6' => ['files' => ['regex.json' => '{"phpVersion": {"min": "8.1", "max": "8.6"}}'], 'first' => self::quantifierReadFrom($toTheEnd)];
        // 8.4 is past the max: not judged.
        yield 'a regex.json object to 8.3' => ['files' => ['regex.json' => '{"phpVersion": {"min": "8.1", "max": "8.3"}}'], 'first' => self::quantifierReadFrom([80100, 80200, 80300])];
        // A single version stays exact.
        yield 'a single regex.json version' => ['files' => ['regex.json' => '{"phpVersion": "8.1"}', 'composer.json' => '{"require": {"php": ">=8.1"}}'], 'first' => null];
    }

    /**
     * The first point after the floor whose PCRE2 reads "a{,3}" as a
     * quantifier (10.43 or later), null when the floor already does or
     * none does: 8.4 unless this machine links a newer PCRE2 to an older
     * minor.
     *
     * @param list<int> $points the floor first
     */
    private static function quantifierReadFrom(array $points): ?int
    {
        $reads = static fn (int $point): bool => version_compare(self::judgedPcre($point), '10.43', '>=');
        if ($reads($points[0])) {
            return null;
        }

        foreach (\array_slice($points, 1) as $point) {
            if ($reads($point)) {
                return $point;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $initializeParams
     */
    private function serve(array $initializeParams, int $extraDocuments = 0, ?Regex $regex = null, bool $hover = false, ?string $pattern = null): void
    {
        $messages = [
            ['id' => 1, 'method' => 'initialize', 'params' => $initializeParams],
            ['method' => 'initialized', 'params' => []],
            $this->didOpen(self::DOCUMENT_URI, $pattern),
        ];
        if ($hover) {
            $messages[] = ['id' => 2, 'method' => 'textDocument/hover', 'params' => [
                'textDocument' => ['uri' => self::DOCUMENT_URI],
                'position' => ['line' => 2, 'character' => 13],
            ]];
        }
        for ($i = 1; $i <= $extraDocuments; $i++) {
            $messages[] = $this->didOpen('file:///workspace/src/Other'.$i.'.php');
        }

        $payload = '';
        foreach ($messages as $message) {
            $json = (string) json_encode(['jsonrpc' => '2.0'] + $message);
            $payload .= 'Content-Length: '.\strlen($json)."\r\n\r\n".$json;
        }

        $input = fopen('php://memory', 'r+');
        if (false === $input) {
            self::fail('Unable to open an in-memory stream.');
        }
        fwrite($input, $payload);
        rewind($input);

        (new Server($regex, $input, transport: new Transport($this->output)))->run();
    }

    /**
     * @return array<string, mixed>
     */
    private function didOpen(string $uri, ?string $pattern = null): array
    {
        return ['method' => 'textDocument/didOpen', 'params' => ['textDocument' => [
            'uri' => $uri,
            'languageId' => 'php',
            'version' => 1,
            'text' => "<?php\n\npreg_match('".($pattern ?? self::NEWER_ENGINE_ONLY)."', \$subject);\n",
        ]]];
    }

    private function rootUri(): string
    {
        return FileUri::of($this->root);
    }

    /**
     * @return list<mixed>
     */
    private function diagnostics(): array
    {
        foreach ($this->notifications('textDocument/publishDiagnostics') as $params) {
            if (self::DOCUMENT_URI === ($params['uri'] ?? null) && \is_array($params['diagnostics'] ?? null)) {
                return array_values($params['diagnostics']);
            }
        }

        self::fail('The server published no diagnostics for the document.');
    }

    private function targetLog(): string
    {
        $logs = $this->targetLogs();
        $this->assertNotSame([], $logs, 'The server logged no target through window/logMessage.');

        return $logs[0];
    }

    /**
     * The window/logMessage notifications that name a PCRE2 release.
     *
     * @return list<string>
     */
    private function targetLogs(): array
    {
        $logs = [];
        foreach ($this->notifications('window/logMessage') as $params) {
            $message = $params['message'] ?? null;
            if (\is_string($message) && str_contains($message, 'PCRE2')) {
                $this->assertIsInt($params['type'] ?? null);
                $logs[] = $message;
            }
        }

        return $logs;
    }

    /**
     * The response to the request of that id, null when none was written.
     *
     * @return array<array-key, mixed>|null
     */
    private function response(int $id): ?array
    {
        rewind($this->output);
        foreach (explode('Content-Length: ', (string) stream_get_contents($this->output)) as $frame) {
            $json = strstr($frame, '{');
            $decoded = false === $json ? null : json_decode($json, true);
            if (\is_array($decoded) && $id === ($decoded['id'] ?? null) && !isset($decoded['method'])) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Every response to the request of that id, in the order written.
     *
     * @return list<array<array-key, mixed>>
     */
    private function responses(int $id): array
    {
        rewind($this->output);
        $responses = [];
        foreach (explode('Content-Length: ', (string) stream_get_contents($this->output)) as $frame) {
            $json = strstr($frame, '{');
            $decoded = false === $json ? null : json_decode($json, true);
            if (\is_array($decoded) && $id === ($decoded['id'] ?? null) && !isset($decoded['method'])) {
                $responses[] = $decoded;
            }
        }

        return $responses;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notifications(string $method): array
    {
        rewind($this->output);
        $written = (string) stream_get_contents($this->output);

        $found = [];
        foreach (explode('Content-Length: ', $written) as $frame) {
            $json = strstr($frame, '{');
            if (false === $json) {
                continue;
            }

            $decoded = json_decode($json, true);
            if (\is_array($decoded) && $method === ($decoded['method'] ?? null) && \is_array($decoded['params'] ?? null)) {
                /** @var array<string, mixed> $params */
                $params = $decoded['params'];
                $found[] = $params;
            }
        }

        return $found;
    }
}
