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
use PHPRegex\Parser\Text\Path;
use PHPRegex\Tests\TestUtils\FileUri;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The language server runs the ReDoS analysis only when it is asked to:
 * `initializationOptions.redos`, else `checks.redos.enabled` in the
 * workspace's regex.json, the switch `regex lint` reads. Its verdict is
 * published as `regex lint` reports it.
 */
final class ServerRedosTest extends TestCase
{
    private const VULNERABLE = '/(a+)+$/';

    /**
     * @var resource
     */
    private $output;

    /**
     * @var resource
     */
    private $input;

    private string $root;

    protected function setUp(): void
    {
        $output = fopen('php://memory', 'r+');
        $input = fopen('php://memory', 'r+');
        if (false === $output || false === $input) {
            self::fail('Unable to open an in-memory stream.');
        }

        $this->output = $output;
        $this->input = $input;

        $this->root = Path::slashes(sys_get_temp_dir()).'/regex-parser-lsp-redos-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        fclose($this->output);
        fclose($this->input);

        foreach (scandir($this->root) ?: [] as $entry) {
            if (!\in_array($entry, ['.', '..'], true)) {
                unlink($this->root.'/'.$entry);
            }
        }
        rmdir($this->root);
    }

    #[Test]
    public function test_the_analysis_is_off_by_default(): void
    {
        $this->assertNull($this->redosDiagnostic(self::VULNERABLE));
    }

    #[Test]
    public function test_an_initialization_option_turns_the_analysis_on(): void
    {
        $diagnostic = $this->redosDiagnostic(self::VULNERABLE, ['redos' => true]);

        $this->assertNotNull($diagnostic);
        $this->assertSame('php-regex', $diagnostic['source']);
        $this->assertContains($diagnostic['severity'], [1, 2]);
        $this->assertIsString($diagnostic['message']);
        $this->assertStringContainsString('Severity: ', (string) $diagnostic['message']);
        $this->assertStringContainsString('Attack: ', (string) $diagnostic['message'], 'The attack the analysis found is shown under the verdict.');
    }

    #[Test]
    public function test_the_diagnostic_covers_the_risky_part(): void
    {
        $diagnostic = $this->redosDiagnostic(self::VULNERABLE, ['redos' => true]);

        $this->assertNotNull($diagnostic);
        // The body (a+)+$ starts at character 13, past preg_match('/; the
        // hotspot is its a+, offsets 1 to 3.
        $this->assertSame([
            'start' => ['line' => 1, 'character' => 14],
            'end' => ['line' => 1, 'character' => 16],
        ], $diagnostic['range']);
    }

    #[Test]
    public function test_regex_json_turns_the_analysis_on_as_for_the_command_line(): void
    {
        file_put_contents($this->root.'/regex.json', '{"checks": {"redos": {"enabled": true}}}');

        $this->assertNotNull($this->redosDiagnostic(self::VULNERABLE));
    }

    #[Test]
    public function test_the_initialization_option_prevails_over_regex_json(): void
    {
        file_put_contents($this->root.'/regex.json', '{"checks": {"redos": {"enabled": true}}}');

        $this->assertNull($this->redosDiagnostic(self::VULNERABLE, ['redos' => false]));
    }

    #[Test]
    public function test_the_threshold_of_regex_json_is_read(): void
    {
        file_put_contents($this->root.'/regex.json', '{"checks": {"redos": {"enabled": true, "threshold": "critical"}}}');
        $critical = $this->redosDiagnostic(self::VULNERABLE);

        file_put_contents($this->root.'/regex.json', '{"checks": {"redos": {"enabled": true, "threshold": "low"}}}');
        $low = $this->redosDiagnostic('/\d+\s*\d+$/');

        unlink($this->root.'/regex.json');

        $this->assertNotNull($critical, '(a+)+ is critical.');
        $this->assertNotNull($low, 'A low threshold reports the polynomial verdict a high one leaves out.');
        $this->assertNull($this->redosDiagnostic('/\d+\s*\d+$/', ['redos' => true]), 'The default threshold is high, as for regex lint.');
    }

    /**
     * The nested quantifier the lint guesses risky goes once the analysis
     * proves the pattern linear, as in `regex lint`.
     */
    #[Test]
    public function test_a_heuristic_warning_goes_when_the_analysis_proves_the_pattern_linear(): void
    {
        $this->assertContains('regex.lint.quantifier.nested', array_column($this->diagnostics('/(c?)+$/'), 'code'));
        $this->assertNotContains('regex.lint.quantifier.nested', array_column($this->diagnostics('/(c?)+$/', ['redos' => true]), 'code'));
    }

    /**
     * The quadratic search over a linear attempt is published under its
     * own id at a medium threshold, and its lint rule turns it off.
     */
    #[Test]
    public function test_the_search_cost_is_published_at_a_medium_threshold(): void
    {
        $this->assertNotContains('regex.lint.redos.search', array_column($this->diagnostics('/\s+$/', ['redos' => true]), 'code'));

        file_put_contents($this->root.'/regex.json', '{"checks": {"redos": {"enabled": true, "threshold": "medium"}}}');
        $search = array_values(array_filter($this->diagnostics('/\s+$/'), static fn (array $diagnostic): bool => 'regex.lint.redos.search' === $diagnostic['code']));
        $this->assertCount(1, $search);
        $this->assertSame(2, $search[0]['severity']);
        $range = $search[0]['range'];
        $this->assertIsArray($range);
        $this->assertSame(['line' => 1, 'character' => 13], $range['start'], 'The search cost covers the whole body.');

        file_put_contents($this->root.'/regex.json', '{"checks": {"redos": {"enabled": true, "threshold": "medium"}, "lint": {"rules": {"redos.search": false}}}}');
        $this->assertNotContains('regex.lint.redos.search', array_column($this->diagnostics('/\s+$/'), 'code'));
    }

    #[Test]
    public function test_a_server_started_with_a_regex_runs_the_analysis_when_asked(): void
    {
        $this->assertNotNull($this->redosDiagnostic(self::VULNERABLE, ['redos' => true], Regex::create(['cache' => null])));
        $this->assertNull($this->redosDiagnostic(self::VULNERABLE, null, Regex::create(['cache' => null])));
    }

    #[Test]
    public function test_a_safe_pattern_gets_no_verdict(): void
    {
        $this->assertNull($this->redosDiagnostic('/^[a-z]+$/', ['redos' => true]));
    }

    /**
     * @param array<string, mixed>|null $options
     *
     * @return array<mixed>|null
     */
    private function redosDiagnostic(string $pattern, ?array $options = null, ?Regex $regex = null): ?array
    {
        foreach ($this->diagnostics($pattern, $options, $regex) as $diagnostic) {
            if ('regex.lint.redos' === ($diagnostic['code'] ?? null)) {
                return $diagnostic;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $options
     *
     * @return list<array<mixed>>
     */
    private function diagnostics(string $pattern, ?array $options = null, ?Regex $regex = null): array
    {
        $params = ['rootUri' => FileUri::of($this->root)];
        if (null !== $options) {
            $params['initializationOptions'] = $options;
        }

        ftruncate($this->output, 0);
        rewind($this->output);
        ftruncate($this->input, 0);
        rewind($this->input);
        foreach ([
            ['id' => 1, 'method' => 'initialize', 'params' => $params],
            ['method' => 'initialized', 'params' => []],
            ['method' => 'textDocument/didOpen', 'params' => ['textDocument' => [
                'uri' => FileUri::of($this->root.'/a.php'),
                'languageId' => 'php',
                'version' => 1,
                'text' => "<?php\npreg_match('".$pattern."', \$subject);\n",
            ]]],
        ] as $message) {
            $json = (string) json_encode(['jsonrpc' => '2.0'] + $message);
            fwrite($this->input, 'Content-Length: '.\strlen($json)."\r\n\r\n".$json);
        }
        rewind($this->input);

        (new Server($regex, $this->input, transport: new Transport($this->output)))->run();

        rewind($this->output);
        $published = [];
        foreach (preg_split('/Content-Length: \d+\r\n\r\n/', (string) stream_get_contents($this->output)) ?: [] as $body) {
            $message = json_decode($body, true);
            if (!\is_array($message) || 'textDocument/publishDiagnostics' !== ($message['method'] ?? null) || !\is_array($message['params'] ?? null)) {
                continue;
            }

            $diagnostics = $message['params']['diagnostics'] ?? [];
            $this->assertIsArray($diagnostics);
            foreach ($diagnostics as $diagnostic) {
                $this->assertIsArray($diagnostic);
                $published[] = $diagnostic;
            }
        }

        return $published;
    }
}
