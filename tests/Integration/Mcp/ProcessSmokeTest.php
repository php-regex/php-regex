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

namespace PHPRegex\Tests\Integration\Mcp;

use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Schema\Tool;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A real client over a real process, the path a coding agent takes:
 * `regex mcp` and `regex-mcp` from the source checkout. The phar's entry is
 * checked by the release workflow, which builds it.
 */
#[Group('slow')]
#[RequiresFunction('proc_open')]
final class ProcessSmokeTest extends TestCase
{
    /**
     * @param list<string> $command
     */
    #[Test]
    #[DataProvider('provideEntries')]
    public function test_a_client_lists_and_calls_the_tools(array $command): void
    {
        // Hard deadlines on the handshake and on every request: a server that
        // never answers fails the test, it never holds the suite.
        $client = Client::builder()->setClientInfo('php-regex tests', Regex::VERSION)->setInitTimeout(30)->setRequestTimeout(30)->build();
        // The SDK is installed for the tests only, beside PHPUnit: the server
        // process reads that autoloader first, as the phar carries its own.
        $client->connect(new StdioTransport(command: \PHP_BINARY, args: ['-d', 'auto_prepend_file='.self::sdkAutoloader(), ...$command], cwd: \dirname(__DIR__, 3)));

        try {
            $tools = array_map(static fn (Tool $tool): string => $tool->name, $client->listTools()->tools);
            $this->assertSame(['regex_validate', 'regex_explain', 'regex_format', 'regex_transpile', 'regex_redos'], $tools);

            $answer = $client->callTool('regex_validate', ['pattern' => '/a+/']);
            $this->assertFalse($answer->isError);
            $structured = JsonContract::asArray($answer->structuredContent);
            $this->assertSame('/a+/', $structured['pattern']);
            $this->assertTrue(JsonContract::asArray($structured['validation'])['is_valid']);
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @return iterable<string, array{command: list<string>}>
     */
    public static function provideEntries(): iterable
    {
        $root = \dirname(__DIR__, 3);

        yield 'regex mcp' => ['command' => [$root.'/bin/regex', 'mcp']];
        yield 'regex-mcp' => ['command' => [$root.'/src/Mcp/bin/regex-mcp']];
    }

    private static function sdkAutoloader(): string
    {
        $autoloader = (new \ReflectionClass(Client::class))->getFileName();
        self::assertIsString($autoloader);

        return \dirname($autoloader, 4).'/autoload.php';
    }
}
