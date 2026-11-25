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
use PHPRegex\Linter\RedosVerdict;
use PHPRegex\Parser\Text\Path;
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Tests\TestUtils\FileUri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The search cost of the functions that look for every match, published
 * as `regex lint` reports it: regex.lint.redos.search at the calls that run
 * their loop, which PHP retries after each empty match; nothing at a call
 * that stops at the first match.
 */
final class ServerEveryMatchSearchCostTest extends TestCase
{
    private const SEARCH = 'regex.lint.redos.search';

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

        $this->root = Path::fromHost(sys_get_temp_dir()).'/regex-parser-lsp-every-match-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0o700, true);
        file_put_contents($this->root.'/regex.json', '{"checks": {"redos": {"enabled": true, "threshold": "medium"}}}');
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

    /**
     * @param list<string> $messages
     */
    #[Test]
    #[DataProvider('provideCalls')]
    public function test_the_every_match_search_cost_is_published_where_the_call_loops(string $code, array $messages): void
    {
        $pattern = PcreInterpreter::pattern('/(?:a+\d)?/');
        $subject = str_repeat('a', 1000).'!';
        $this->assertSame(1002, preg_match_all($pattern, $subject), 'Oracle: preg_match_all() stops before the end of the run.');
        $this->assertSame(1, preg_match($pattern, $subject), 'Oracle: preg_match() goes on.');
        $retry = PcreAttemptSteps::pinned('/(*NOTEMPTY_ATSTART)(?:a+\d)?/');
        $this->assertGreaterThanOrEqual(250, PcreAttemptSteps::steps($retry, $subject, 250) - PcreAttemptSteps::steps($retry, $subject, 500), 'Oracle: the retry does not read the run.');

        $published = array_values(array_filter($this->diagnostics($code), static fn (array $diagnostic): bool => self::SEARCH === $diagnostic['code']));

        // The message, then the evidence, a line each.
        $lines = array_map(static fn (mixed $message): array => explode("\n", \is_string($message) ? $message : ''), array_column($published, 'message'));
        $this->assertSame($messages, array_map(static fn (array $message): string => $message[0], $lines));
        foreach ($published as $index => $diagnostic) {
            $this->assertSame(2, $diagnostic['severity'], 'A warning.');
            $this->assertContains('Attack: "a" x n.', $lines[$index]);
        }
    }

    /**
     * @return iterable<string, array{code: string, messages: list<string>}>
     */
    public static function provideCalls(): iterable
    {
        yield 'preg_match_all' => ['code' => "preg_match_all('/(?:a+\\d)?/', \$s);", 'messages' => [RedosVerdict::everyMatchMessage('preg_match_all')]];
        yield 'preg_split' => ['code' => "preg_split('/(?:a+\\d)?/', \$s);", 'messages' => [RedosVerdict::everyMatchMessage('preg_split')]];
        yield 'preg_match' => ['code' => "preg_match('/(?:a+\\d)?/', \$s);", 'messages' => []];
        yield 'preg_match_all of a pattern whose loop is linear' => ['code' => "preg_match_all('/a/', \$s);", 'messages' => []];
        yield 'preg_replace bounded by a limit' => ['code' => "preg_replace('/(?:a+\\d)?/', '', \$s, 1);", 'messages' => []];
        yield 'a literal preg_match_all reads through a variable' => ['code' => "\$p = '/(?:a+\\d)?/';\npreg_match_all(\$p, \$s);", 'messages' => [RedosVerdict::everyMatchMessage('preg_match_all')]];
        yield 'a literal preg_match reads before preg_match_all' => ['code' => "function f(\$s) {\n\$p = '/(?:a+\\d)?/';\npreg_match(\$p, \$s);\npreg_match_all(\$p, \$s);\n}", 'messages' => [RedosVerdict::everyMatchMessage('preg_match_all')]];
        yield 'a literal two loops read' => ['code' => "function f(\$s) {\n\$p = '/(?:a+\\d)?/';\npreg_match_all(\$p, \$s);\npreg_split(\$p, \$s);\n}", 'messages' => [RedosVerdict::everyMatchMessage('preg_match_all'), RedosVerdict::everyMatchMessage('preg_split')]];
        yield 'a constant preg_match reads before preg_replace' => ['code' => "const P = '/(?:a+\\d)?/';\nfunction f(\$s) { preg_match(P, \$s); return preg_replace(P, '', \$s); }", 'messages' => [RedosVerdict::everyMatchMessage('preg_replace')]];
    }

    /**
     * The lint rule of the search cost turns it off.
     */
    #[Test]
    public function test_the_every_match_search_cost_is_turned_off_with_the_search_cost(): void
    {
        $this->assertContains(self::SEARCH, array_column($this->diagnostics("preg_match_all('/(?:a+\\d)?/', \$s);"), 'code'), 'control: published');

        file_put_contents($this->root.'/regex.json', '{"checks": {"redos": {"enabled": true, "threshold": "medium"}, "lint": {"rules": {"redos.search": false}}}}');

        $this->assertNotContains(self::SEARCH, array_column($this->diagnostics("preg_match_all('/(?:a+\\d)?/', \$s);"), 'code'));
    }

    /**
     * @return list<array<mixed>>
     */
    private function diagnostics(string $code): array
    {
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
                'text' => "<?php\n".$code."\n",
            ]]],
        ] as $message) {
            $json = (string) json_encode(['jsonrpc' => '2.0'] + $message);
            fwrite($this->input, 'Content-Length: '.\strlen($json)."\r\n\r\n".$json);
        }
        rewind($this->input);

        (new Server(null, $this->input, transport: new Transport($this->output)))->run();

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
