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

namespace PhpRegex\Tests\Unit\Lsp;

use PhpRegex\LanguageServer\Protocol\Response;
use PhpRegex\LanguageServer\Server;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the language server does with a target it cannot use, and with a
 * Regex it was handed: an unreadable initializationOptions version is a
 * warning and the next source is used; an injected Regex is kept whatever
 * the workspace says; what the resolution noticed on the way is logged
 * apart from the target line.
 */
final class ServerTargetFallbackTest extends TestCase
{
    /**
     * "(?a)" arrived in PCRE2 10.43: 10.40 (PHP 8.2) refuses it, 10.44
     * (PHP 8.4) compiles it.
     */
    private const NEWER_ENGINE_ONLY = '/(?a)x/';

    private const DOCUMENT_URI = 'file:///workspace/src/Pattern.php';

    private const LOG_WARNING = 2;

    private const LOG_INFO = 3;

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

        $this->root = sys_get_temp_dir().'/regex-parser-lsp-fallback-'.bin2hex(random_bytes(6));
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
    public function test_an_unreadable_php_version_option_falls_back_to_regex_json(): void
    {
        file_put_contents($this->root.'/regex.json', (string) json_encode(['phpVersion' => '8.2']));

        $this->serve(['rootUri' => $this->rootUri(), 'initializationOptions' => ['phpVersion' => 'eight']]);

        $this->assertNotSame([], $this->diagnostics(), 'regex.json asks for PHP 8.2, whose PCRE2 10.40 refuses "(?a)".');
        $this->assertStringContainsString('regex.json', $this->targetLog()['message']);

        $warnings = $this->logs(self::LOG_WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('initializationOptions.phpVersion', $warnings[0]);
        $this->assertStringContainsString('"eight"', $warnings[0]);
    }

    #[Test]
    public function test_an_unreadable_regex_json_is_a_warning(): void
    {
        file_put_contents($this->root.'/regex.json', '{"phpVersion": "eight"}');

        $this->serve(['rootUri' => $this->rootUri()]);

        $this->assertStringContainsString('running PHP', $this->targetLog()['message']);
        $warnings = $this->logs(self::LOG_WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('regex.json', $warnings[0]);
    }

    #[Test]
    public function test_a_drive_letter_uri_that_is_no_local_directory_judges_for_the_running_php(): void
    {
        // file:///C:/... names C:/...; where no such folder exists,
        // nothing is read from it.
        $this->serve(['rootUri' => 'file:///C:/regex-parser-nowhere']);

        $this->assertStringContainsString('running PHP', $this->targetLog()['message']);
        $this->assertSame([], $this->logs(self::LOG_WARNING));
    }

    #[Test]
    public function test_an_injected_regex_is_kept(): void
    {
        // The workspace asks for PHP 8.4 (PCRE2 10.44, which compiles
        // "(?a)"); the Regex handed to the server judges for PHP 8.2.
        $this->serve(
            ['rootUri' => $this->rootUri(), 'initializationOptions' => ['phpVersion' => '8.4']],
            Regex::create(['php_version' => '8.2']),
        );

        $this->assertNotSame([], $this->diagnostics());
        $this->assertSame([], $this->logs(self::LOG_WARNING));
    }

    #[Test]
    public function test_the_target_line_is_an_info_log(): void
    {
        file_put_contents($this->root.'/regex.json', (string) json_encode(['phpVersion' => '8.2']));

        $this->serve(['rootUri' => $this->rootUri()]);

        $this->assertSame(self::LOG_INFO, $this->targetLog()['type']);
    }

    #[Test]
    public function test_a_composer_notice_is_logged_apart_from_the_target(): void
    {
        file_put_contents($this->root.'/composer.json', (string) json_encode(['name' => 'acme/app']));

        $this->serve(['rootUri' => $this->rootUri()]);

        $this->assertStringContainsString('running PHP', $this->targetLog()['message']);
        $notices = array_values(array_filter(
            $this->logs(self::LOG_INFO),
            static fn (string $message): bool => str_contains($message, 'require.php'),
        ));
        $this->assertCount(1, $notices);
        $this->assertStringNotContainsString('PCRE2', $notices[0]);
    }

    /**
     * @param array<string, mixed> $initializeParams
     */
    private function serve(array $initializeParams, ?Regex $regex = null): void
    {
        $messages = [
            ['id' => 1, 'method' => 'initialize', 'params' => $initializeParams],
            ['method' => 'initialized', 'params' => []],
            ['method' => 'textDocument/didOpen', 'params' => ['textDocument' => [
                'uri' => self::DOCUMENT_URI,
                'languageId' => 'php',
                'version' => 1,
                'text' => "<?php\n\npreg_match('".self::NEWER_ENGINE_ONLY."', \$subject);\n",
            ]]],
        ];

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

        (new Server($regex, $input))->run();
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

    /**
     * The one window/logMessage that names a PCRE2 release.
     *
     * @return array{type: mixed, message: string}
     */
    private function targetLog(): array
    {
        $found = [];
        foreach ($this->notifications('window/logMessage') as $params) {
            $message = $params['message'] ?? null;
            if (\is_string($message) && str_contains($message, 'PCRE2')) {
                $found[] = ['type' => $params['type'] ?? null, 'message' => $message];
            }
        }

        $this->assertCount(1, $found, 'The server did not log the target once.');

        return $found[0];
    }

    /**
     * The window/logMessage messages of one type.
     *
     * @return list<string>
     */
    private function logs(int $type): array
    {
        $messages = [];
        foreach ($this->notifications('window/logMessage') as $params) {
            if ($type === ($params['type'] ?? null) && \is_string($params['message'] ?? null)) {
                $messages[] = $params['message'];
            }
        }

        return $messages;
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
