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

namespace PHPRegex\Cli\Command;

use PHPRegex\Cli\GlobalOptionsParser;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Option\Arguments;
use PHPRegex\Cli\Option\Options;
use PHPRegex\Cli\Output;

/**
 * regex mcp: the MCP server over stdin and stdout, for a coding agent's
 * client. The server lives in its own package, started here by class name
 * so that the CLI runs without it; the SDK it speaks through is probed
 * too, because a monorepo install carries the package without the SDK.
 *
 * @internal
 */
final class McpCommand extends AbstractCommand
{
    /**
     * @param string $applicationClass the server's entry class, named rather than imported: the CLI package runs without it
     * @param string $sdkClass         a class of the SDK the server speaks through, which the package requires and the monorepo does not
     */
    public function __construct(private readonly string $applicationClass = 'PHPRegex\Mcp\Application', private readonly string $sdkClass = 'Mcp\Server') {}

    public function getName(): string
    {
        return 'mcp';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return 'Run the MCP server over stdio';
    }

    /**
     * The options of the command, which its help lists.
     */
    public static function options(): Options
    {
        return new Options(Arguments::None, [
            ...GlobalOptionsParser::target(),
        ], "Usage: regex mcp [--php-version=<ver>] [--pcre-version=<ver>] [--locale=<name>]\n");
    }

    public function run(Input $input, Output $output): int
    {
        $usage = self::options()->usage;
        $error = self::options()->parse($input->args)->error;
        if (null !== $error) {
            return $this->usageError($output, $error, $usage);
        }

        if (!class_exists($this->applicationClass)) {
            $output->writeError("The MCP server is not installed: run composer require php-regex/regex-mcp, or run the regex.phar, which carries it.\n");

            return self::INVALID;
        }

        if (!class_exists($this->sdkClass)) {
            $output->writeError("The MCP server needs the mcp/sdk library: run composer require mcp/sdk, or run the regex.phar, which carries it.\n");

            return self::INVALID;
        }

        $application = new $this->applicationClass();
        if (!method_exists($application, 'run')) {
            $output->writeError(\sprintf("%s is not an MCP server.\n", $this->applicationClass));

            return self::INVALID;
        }

        $exitCode = $application->run($input->regexOptions);

        return \is_int($exitCode) ? $exitCode : self::FAILURE;
    }
}
