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

use Mcp\Server;
use PHPRegex\Cli\ApplicationFactory;
use PHPRegex\Cli\Command\HelpCommand;
use PHPRegex\Cli\Command\McpCommand;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPRegex\Tests\Support\StubMcpApplication;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class McpCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        StubMcpApplication::$exitCode = 0;
        StubMcpApplication::$target = null;
    }

    #[Test]
    public function test_the_cli_serves_an_mcp_session_over_stdio(): void
    {
        $initialize = (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '0']]]);
        $initialized = (string) json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $list = (string) json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => (object) []]);

        $directory = sys_get_temp_dir().'/regex-mcp-command-'.bin2hex(random_bytes(4));
        mkdir($directory);

        try {
            file_put_contents($directory.'/stdin', $initialize."\n".$initialized."\n".$list."\n");
            // The SDK is installed for the tests only, beside PHPUnit: the
            // server process reads that autoloader first.
            $sdk = (new \ReflectionClass(Server::class))->getFileName();
            $this->assertIsString($sdk);
            $process = proc_open(
                [\PHP_BINARY, '-d', 'auto_prepend_file='.\dirname($sdk, 4).'/autoload.php', \dirname(__DIR__, 3).'/bin/regex', 'mcp'],
                [0 => ['file', $directory.'/stdin', 'r'], 1 => ['file', $directory.'/stdout', 'w'], 2 => ['file', $directory.'/stderr', 'w']],
                $pipes,
                $directory,
            );
            $this->assertIsResource($process);
            // A hard deadline: a server that never sees the end of its input
            // is killed and the test fails with what it wrote, never hangs.
            $deadline = microtime(true) + 30;
            $status = proc_get_status($process);
            while ($status['running'] && microtime(true) < $deadline) {
                usleep(50_000);
                $status = proc_get_status($process);
            }
            if ($status['running']) {
                proc_terminate($process, 9);
                proc_close($process);
                $this->fail("regex mcp did not end within 30 s.\nstdout: ".(string) file_get_contents($directory.'/stdout')."\nstderr: ".(string) file_get_contents($directory.'/stderr'));
            }
            // Before PHP 8.3, proc_close() answers -1 once proc_get_status()
            // has read the exit code: the last status holds it then.
            $closed = proc_close($process);
            $exitCode = -1 === $closed ? $status['exitcode'] : $closed;

            $stdout = (string) file_get_contents($directory.'/stdout');
            $this->assertSame(0, $exitCode, (string) file_get_contents($directory.'/stderr'));
            $this->assertStringStartsWith('{"jsonrpc":"2.0"', $stdout, 'Nothing but JSON lines on stdout: no banner.');
            $this->assertStringContainsString('"serverInfo":{"name":"php-regex"', $stdout);
            $this->assertStringContainsString('"name":"regex_validate"', $stdout);
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    #[Test]
    #[DataProvider('provideServerExitCodes')]
    public function test_the_command_exits_with_the_session_code(int|string $sessionCode, int $exitCode): void
    {
        StubMcpApplication::$exitCode = $sessionCode;

        $this->assertSame($exitCode, (new McpCommand(StubMcpApplication::class, \stdClass::class))->run(new Input('mcp', [], new GlobalOptions(false, null, false, false, null, null), []), new Output(false, false)));
    }

    /**
     * @return iterable<string, array{sessionCode: int|string, exitCode: int}>
     */
    public static function provideServerExitCodes(): iterable
    {
        yield 'a session ended at the end of input' => ['sessionCode' => 0, 'exitCode' => 0];
        yield 'a session the transport failed' => ['sessionCode' => 1, 'exitCode' => 1];
        yield 'a server answering no code' => ['sessionCode' => 'done', 'exitCode' => 1];
    }

    #[Test]
    public function test_the_target_of_the_command_line_is_handed_to_the_server(): void
    {
        $globals = new GlobalOptions(false, null, false, false, '8.2', null, '10.40', 'C');

        (new McpCommand(StubMcpApplication::class, \stdClass::class))->run(new Input('mcp', [], $globals, ['php_version' => '8.2', 'pcre_version' => '10.40', 'locale' => 'C']), new Output(false, false));

        $this->assertSame(['php_version' => '8.2', 'pcre_version' => '10.40', 'locale' => 'C'], StubMcpApplication::$target);
    }

    #[Test]
    public function test_the_command_takes_no_argument(): void
    {
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($errors);

        $exitCode = (new McpCommand(StubMcpApplication::class, \stdClass::class))->run(new Input('mcp', ['/a+/'], new GlobalOptions(false, null, false, false, null, null), []), new Output(false, false, '#', '-', $errors));

        rewind($errors);
        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Usage: regex mcp', (string) stream_get_contents($errors));
        $this->assertNull(StubMcpApplication::$target);
    }

    #[Test]
    public function test_without_the_package_installed_the_command_says_what_to_install(): void
    {
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($errors);

        $exitCode = (new McpCommand('PHPRegex\Mcp\Missing', \stdClass::class))->run(new Input('mcp', [], new GlobalOptions(false, null, false, false, null, null), []), new Output(false, false, '#', '-', $errors));

        rewind($errors);
        $this->assertSame(2, $exitCode);
        $this->assertSame("The MCP server is not installed: run composer require php-regex/regex-mcp, or run the regex.phar, which carries it.\n", (string) stream_get_contents($errors));
    }

    #[Test]
    public function test_without_the_sdk_installed_the_command_says_what_to_install(): void
    {
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($errors);

        $exitCode = (new McpCommand(StubMcpApplication::class, 'Mcp\Missing'))->run(new Input('mcp', [], new GlobalOptions(false, null, false, false, null, null), []), new Output(false, false, '#', '-', $errors));

        rewind($errors);
        $this->assertSame(2, $exitCode);
        $this->assertSame("The MCP server needs the mcp/sdk library: run composer require mcp/sdk, or run the regex.phar, which carries it.\n", (string) stream_get_contents($errors));
        $this->assertNull(StubMcpApplication::$target);
    }

    #[Test]
    public function test_a_class_that_is_no_server_is_refused(): void
    {
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($errors);

        $exitCode = (new McpCommand(\stdClass::class, \stdClass::class))->run(new Input('mcp', [], new GlobalOptions(false, null, false, false, null, null), []), new Output(false, false, '#', '-', $errors));

        rewind($errors);
        $this->assertSame(2, $exitCode);
        $this->assertSame("stdClass is not an MCP server.\n", (string) stream_get_contents($errors));
    }

    #[Test]
    public function test_the_help_page_says_what_the_server_speaks(): void
    {
        $help = (new HelpCommand())->withCommands(ApplicationFactory::commands(new HelpCommand()));

        ob_start();
        $help->run(new Input('help', ['mcp'], new GlobalOptions(false, null, false, false, null, null), []), new Output(false, false));
        $page = (string) ob_get_clean();

        $this->assertStringContainsString('Run the MCP server over stdio', $page);
        $this->assertStringContainsString('Speaks the Model Context Protocol on stdin and stdout, as vendor/bin/regex-mcp does.', $page);
        $this->assertStringContainsString('--php-version, --pcre-version and --locale set the target every tool judges for; a call may name its own php_version and pcre_version.', $page);
        $this->assertStringContainsString('Start the server, as a client does', $page);
    }

    #[Test]
    public function test_the_summary_lists_mcp_after_lsp(): void
    {
        $help = (new HelpCommand())->withCommands(ApplicationFactory::commands(new HelpCommand()));

        ob_start();
        $help->run(new Input('help', [], new GlobalOptions(false, null, false, false, null, null), []), new Output(false, false));
        $page = (string) ob_get_clean();

        $this->assertMatchesRegularExpression('~^\s+lsp\s+Run the language server over stdio\n\s+mcp\s+Run the MCP server over stdio$~m', $page);
    }
}
