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

namespace RegexParser\Tests\Unit\Lsp;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Lsp\Protocol\Response;
use RegexParser\Lsp\Server;
use RegexParser\Regex;

/**
 * The language server judges a workspace for its target, resolved once at
 * "initialize": initializationOptions.phpVersion / pcreVersion, else
 * regex.json at the root folder, else composer.json there, else the running
 * PHP; the target and where it came from are logged once through
 * window/logMessage, and the diagnostics judge for it.
 */
final class ServerTargetTest extends TestCase
{
    /**
     * "(?a)", ASCII-only classes, arrived in PCRE2 10.43
     * (PcreFeature::AsciiOptions): PHP 8.2's 10.40 refuses it, this PHP's
     * PCRE2 (10.49) compiles it — preg_match() returns 0, not false.
     */
    private const NEWER_ENGINE_ONLY = '/(?a)x/';

    private const DOCUMENT_URI = 'file:///workspace/src/Pattern.php';

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
        Response::writeTo($this->output);

        $this->root = sys_get_temp_dir().'/regex-parser-lsp-target-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        Response::writeTo(null);
        fclose($this->output);

        foreach (glob($this->root.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    #[Test]
    public function test_initialization_options_set_the_target(): void
    {
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
        file_put_contents($this->root.'/regex.json', (string) json_encode(['phpVersion' => '8.2']));
        file_put_contents($this->root.'/composer.json', (string) json_encode(['require' => ['php' => '>=8.4']]));

        $this->serve(['rootUri' => $this->rootUri()]);

        $this->assertStringContainsString('regex.json', $this->targetLog());
        $this->assertNotSame([], $this->diagnostics());
    }

    #[Test]
    public function test_composer_json_of_the_first_workspace_folder_sets_the_target(): void
    {
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

    #[Test]
    public function test_the_target_is_logged_once(): void
    {
        file_put_contents($this->root.'/regex.json', (string) json_encode(['phpVersion' => '8.2']));

        // Two documents opened: the target is resolved at initialize only.
        $this->serve(['rootUri' => $this->rootUri()], extraDocuments: 1);

        $this->assertCount(1, $this->targetLogs());
    }

    /**
     * @param array<string, mixed> $initializeParams
     */
    private function serve(array $initializeParams, int $extraDocuments = 0): void
    {
        $messages = [
            ['id' => 1, 'method' => 'initialize', 'params' => $initializeParams],
            ['method' => 'initialized', 'params' => []],
            $this->didOpen(self::DOCUMENT_URI),
        ];
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

        (new Server(null, $input))->run();
    }

    /**
     * @return array<string, mixed>
     */
    private function didOpen(string $uri): array
    {
        return ['method' => 'textDocument/didOpen', 'params' => ['textDocument' => [
            'uri' => $uri,
            'languageId' => 'php',
            'version' => 1,
            'text' => "<?php\n\npreg_match('".self::NEWER_ENGINE_ONLY."', \$subject);\n",
        ]]];
    }

    private function rootUri(): string
    {
        return 'file://'.$this->root;
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
