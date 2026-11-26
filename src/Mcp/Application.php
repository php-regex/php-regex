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

namespace PHPRegex\Mcp;

use PHPRegex\Mcp\Sdk\SdkServer;
use Psr\Log\LoggerInterface;

/**
 * The server as a command starts it: the catalog for a target, the
 * protocol over stdin and stdout, until the client closes its end. The
 * CLI's `regex mcp` and the package's `regex-mcp` both come here, by class
 * name, with no argument but the target.
 *
 * @phpstan-import-type Target from RegexFactory
 */
final class Application
{
    /**
     * @param LoggerInterface|null $logger where the server logs; stderr when null
     */
    public function __construct(private readonly ?LoggerInterface $logger = null) {}

    /**
     * Serves the library's catalog, every tool judging for the target.
     *
     * @param Target        $target the PHP, PCRE2 and locale every tool judges for unless a call names its own
     * @param resource|null $input  the client's messages; stdin when null
     * @param resource|null $output the answers; stdout when null
     *
     * @return int the exit code
     */
    public function run(array $target = [], $input = null, $output = null): int
    {
        return $this->serve(ToolCatalog::create($target), $input, $output);
    }

    /**
     * Serves the catalog given, as it is.
     *
     * @param resource|null $input  the client's messages; stdin when null
     * @param resource|null $output the answers; stdout when null
     *
     * @return int the exit code
     */
    public function serve(ToolCatalog $catalog, $input = null, $output = null): int
    {
        // A warning PHP prints on stdout would be read as protocol: it goes
        // where the log goes.
        if (\function_exists('ini_set')) {
            ini_set('display_errors', 'stderr');
        }

        $server = new SdkServer($catalog, $this->logger ?? new StderrLogger());

        $input ??= \defined('STDIN') ? \STDIN : fopen('php://stdin', 'r');
        $output ??= \defined('STDOUT') ? \STDOUT : fopen('php://stdout', 'w');
        if (!\is_resource($input) || !\is_resource($output)) {
            // A stream closed before the session, or a PHP that opens neither
            // its standard input nor its standard output.
            return 1;
        }

        return $server->run($input, $output);
    }
}
