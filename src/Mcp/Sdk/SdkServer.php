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

namespace PHPRegex\Mcp\Sdk;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Mcp\Server\Transport\StdioTransport;
use PHPRegex\Mcp\ServerInterface;
use PHPRegex\Mcp\ToolCatalog;
use PHPRegex\Mcp\ToolDefinition;
use PHPRegex\Toolkit\Regex;
use Psr\Log\LoggerInterface;

/**
 * The protocol, spoken by the official SDK (mcp/sdk). The one file of the
 * package that sees an SDK type: the SDK is experimental and moves between
 * minors, so a change of SDK is a change of this file alone. The catalog
 * is registered tool by tool with the schemas it wrote; nothing is derived
 * from a signature.
 *
 * @internal
 */
final class SdkServer implements ServerInterface
{
    public function __construct(private readonly ToolCatalog $catalog, private readonly LoggerInterface $logger) {}

    public function run($input, $output): int
    {
        $builder = Server::builder()
            ->setServerInfo('php-regex', Regex::VERSION)
            ->setInstructions('Read-only questions about PCRE patterns written as in PHP source (/…/flags): whether a PHP compiles one, what it means, how it lays out, how it reads in JavaScript, HTML or Python, and whether it backtracks catastrophically.')
            ->setLogger($this->logger);

        foreach ($this->catalog->tools() as $definition) {
            $builder->add(self::schema($definition), self::handler($definition));
        }

        // The boundary of the process: a transport that fails is logged and
        // answered with an exit code, never a stack trace on the channel.
        try {
            $exitCode = $builder->build()->run(new StdioTransport($input, $output, $this->logger));
        } catch (\Throwable $e) {
            $this->logger->error('The MCP server stopped on a transport failure.', ['exception' => $e]);

            return 1;
        }

        return \is_int($exitCode) ? $exitCode : 1;
    }

    private static function schema(ToolDefinition $definition): Tool
    {
        return new Tool(
            name: $definition->name,
            title: $definition->title,
            inputSchema: $definition->inputSchema,
            description: $definition->description,
            annotations: new ToolAnnotations(
                title: $definition->title,
                readOnlyHint: $definition->annotations['readOnlyHint'],
                destructiveHint: $definition->annotations['destructiveHint'],
                idempotentHint: $definition->annotations['idempotentHint'],
                openWorldHint: $definition->annotations['openWorldHint'],
            ),
            outputSchema: $definition->outputSchema,
        );
    }

    private static function handler(ToolDefinition $definition): ToolHandlerInterface
    {
        return new class($definition) implements ToolHandlerInterface {
            public function __construct(private readonly ToolDefinition $definition) {}

            public function execute(array $arguments, ClientGateway $gateway): CallToolResult
            {
                $result = $this->definition->tool->execute($arguments);

                return new CallToolResult([new TextContent($result->text)], $result->isError, $result->structured);
            }
        };
    }
}
