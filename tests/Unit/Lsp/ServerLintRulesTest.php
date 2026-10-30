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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The language server reports the lint rules `checks.lint.rules` in the
 * workspace's regex.json turns on, and none it turns off, as `regex lint`
 * does: the editor and the command line agree.
 */
final class ServerLintRulesTest extends TestCase
{
    private const DOCUMENT = "<?php\npreg_match('/(a+)+$/', \$subject);\n";

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

        $this->root = Path::fromHost(sys_get_temp_dir()).'/regex-parser-lsp-rules-'.bin2hex(random_bytes(6));
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
    public function test_a_rule_regex_json_turns_off_is_not_reported(): void
    {
        $this->assertContains('regex.lint.quantifier.nested', $this->codesFor(null));
        $this->assertNotContains('regex.lint.quantifier.nested', $this->codesFor('{"checks": {"lint": {"rules": {"quantifier.nested": false}}}}'));
    }

    /**
     * @return list<mixed>
     */
    private function codesFor(?string $config): array
    {
        if (null !== $config) {
            file_put_contents($this->root.'/regex.json', $config);
        }

        ftruncate($this->output, 0);
        rewind($this->output);
        ftruncate($this->input, 0);
        rewind($this->input);
        foreach ([
            ['id' => 1, 'method' => 'initialize', 'params' => ['rootUri' => FileUri::of($this->root)]],
            ['method' => 'initialized', 'params' => []],
            ['method' => 'textDocument/didOpen', 'params' => ['textDocument' => [
                'uri' => FileUri::of($this->root.'/a.php'),
                'languageId' => 'php',
                'version' => 1,
                'text' => self::DOCUMENT,
            ]]],
        ] as $message) {
            $json = (string) json_encode(['jsonrpc' => '2.0'] + $message);
            fwrite($this->input, 'Content-Length: '.\strlen($json)."\r\n\r\n".$json);
        }
        rewind($this->input);

        (new Server(null, $this->input, transport: new Transport($this->output)))->run();

        rewind($this->output);
        $codes = [];
        foreach (preg_split('/Content-Length: \d+\r\n\r\n/', (string) stream_get_contents($this->output)) ?: [] as $body) {
            $message = json_decode($body, true);
            if (\is_array($message) && 'textDocument/publishDiagnostics' === ($message['method'] ?? null) && \is_array($message['params'] ?? null)) {
                $diagnostics = $message['params']['diagnostics'] ?? [];
                $this->assertIsArray($diagnostics);
                foreach ($diagnostics as $diagnostic) {
                    $this->assertIsArray($diagnostic);
                    $codes[] = $diagnostic['code'] ?? null;
                }
            }
        }

        return $codes;
    }
}
