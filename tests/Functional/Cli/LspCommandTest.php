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

use PHPRegex\Cli\ApplicationFactory;
use PHPRegex\Cli\Command\HelpCommand;
use PHPRegex\Cli\Command\LspCommand;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPRegex\Tests\Support\StubLanguageServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "regex lsp" runs the language server from the CLI, so the one download
 * of the phar carries it. The CLI package installed alone does not depend
 * on the server: there, the command says what to install.
 */
final class LspCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/regex-lsp-command-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    #[Test]
    public function test_the_cli_serves_a_language_server_session_over_stdio(): void
    {
        $initialize = (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['capabilities' => []]]);
        $shutdown = (string) json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown']);
        $exit = (string) json_encode(['jsonrpc' => '2.0', 'method' => 'exit']);
        $input = '';
        foreach ([$initialize, $shutdown, $exit] as $json) {
            $input .= 'Content-Length: '.\strlen($json)."\r\n\r\n".$json;
        }
        file_put_contents($this->directory.'/stdin', $input);

        $process = proc_open(
            [\PHP_BINARY, '-d', 'auto_prepend_file=', \dirname(__DIR__, 3).'/bin/regex', 'lsp'],
            [0 => ['file', $this->directory.'/stdin', 'r'], 1 => ['file', $this->directory.'/stdout', 'w'], 2 => ['file', $this->directory.'/stderr', 'w']],
            $pipes,
            $this->directory,
        );
        $this->assertIsResource($process);
        $exitCode = proc_close($process);

        $stdout = (string) file_get_contents($this->directory.'/stdout');
        $this->assertSame(0, $exitCode, (string) file_get_contents($this->directory.'/stderr'));
        $this->assertStringStartsWith('Content-Length: ', $stdout, 'Nothing but frames on stdout: no banner.');
        $this->assertStringContainsString('"serverInfo":{"name":"php-regex-lsp"', $stdout);
        $this->assertStringContainsString('{"jsonrpc":"2.0","id":2,"result":null}', $stdout);
    }

    /**
     * The session's own code is the command's: 1 for an "exit" no
     * "shutdown" came before, and a server answering anything but a code
     * fails.
     */
    #[Test]
    #[DataProvider('provideServerExitCodes')]
    public function test_the_command_exits_with_the_session_code(int|string $sessionCode, int $exitCode): void
    {
        StubLanguageServer::$exitCode = $sessionCode;

        try {
            $this->assertSame($exitCode, (new LspCommand(StubLanguageServer::class))->run(new Input('lsp', [], new GlobalOptions(false, null, false, false, null, null), []), new Output(false, false)));
        } finally {
            StubLanguageServer::$exitCode = 0;
        }
    }

    /**
     * @return iterable<string, array{sessionCode: int|string, exitCode: int}>
     */
    public static function provideServerExitCodes(): iterable
    {
        yield 'a session ended after shutdown' => ['sessionCode' => 0, 'exitCode' => 0];
        yield 'a session ended without shutdown' => ['sessionCode' => 1, 'exitCode' => 1];
        yield 'a server answering no code' => ['sessionCode' => 'done', 'exitCode' => 1];
    }

    #[Test]
    public function test_the_help_page_says_where_the_target_comes_from(): void
    {
        $help = (new HelpCommand())->withCommands(ApplicationFactory::commands(new HelpCommand()));

        ob_start();
        $help->run(new Input('help', ['lsp'], new GlobalOptions(false, null, false, false, null, null), []), new Output(false, false));
        $page = (string) ob_get_clean();

        $this->assertStringContainsString('Run the language server over stdio', $page);
        $this->assertStringContainsString('Speaks the Language Server Protocol on stdin and stdout, as vendor/bin/regex-lsp does.', $page);
        $this->assertStringContainsString("The target is read from the editor's initializationOptions, regex.json or composer.json.", $page);
        $this->assertStringContainsString('Start the server, as an editor does', $page);
    }

    #[Test]
    public function test_a_class_that_is_no_server_is_refused(): void
    {
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($errors);

        $exitCode = (new LspCommand(\stdClass::class))->run(new Input('lsp', [], new GlobalOptions(false, null, false, false, null, null), []), new Output(false, false, '#', '-', $errors));

        rewind($errors);
        $this->assertSame(2, $exitCode);
        $this->assertSame("stdClass is not a language server.\n", (string) stream_get_contents($errors));
    }

    #[Test]
    public function test_without_the_language_server_installed_the_command_says_what_to_install(): void
    {
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($errors);
        $output = new Output(false, false, '#', '-', $errors);

        ob_start();
        $exitCode = (new LspCommand('PHPRegex\LanguageServer\NoSuchServer'))->run(new Input('lsp', [], new GlobalOptions(false, null, false, false, null, null), []), $output);
        $stdout = (string) ob_get_clean();

        rewind($errors);
        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertSame(
            "The language server is not installed: run composer require --dev php-regex/regex-language-server, or run the regex.phar, which carries it.\n",
            (string) stream_get_contents($errors),
        );
    }
}
