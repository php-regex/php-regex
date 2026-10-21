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

use PHPRegex\Cli\Input;
use PHPRegex\Cli\Option\Arguments;
use PHPRegex\Cli\Option\Options;
use PHPRegex\Cli\Output;

/**
 * Runs the language server over stdio, as vendor/bin/regex-lsp does: the
 * phar carries the server, so one download serves the editor too.
 *
 * The CLI package does not depend on the language server: the server is
 * named by its class, and the command says what to install when that
 * class is missing.
 *
 * @internal
 */
final class LspCommand extends AbstractCommand
{
    /**
     * @param string $serverClass the server's class, named rather than
     *                            imported: the CLI package runs without it
     */
    public function __construct(private readonly string $serverClass = 'PHPRegex\LanguageServer\Server') {}

    public function getName(): string
    {
        return 'lsp';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return 'Run the language server over stdio';
    }

    /**
     * The options of the command, which its help lists.
     */
    public static function options(): Options
    {
        return new Options(Arguments::None, [], "Usage: regex lsp\n");
    }

    public function run(Input $input, Output $output): int
    {
        $usage = self::options()->usage;
        $error = self::options()->parse($input->args)->error;
        if (null !== $error) {
            return $this->usageError($output, $error, $usage);
        }

        // The editor, regex.json and composer.json name the target: one
        // given here would be ignored.
        if (null !== $input->globalOptions->phpVersion || null !== $input->globalOptions->pcreVersion || null !== $input->globalOptions->locale) {
            return $this->usageError($output, 'regex lsp reads its target from the editor\'s initializationOptions, regex.json or composer.json, not from --php-version, --pcre-version or --locale.', $usage);
        }

        if (!class_exists($this->serverClass)) {
            $output->writeError("The language server is not installed: run composer require --dev php-regex/regex-language-server, or run the regex.phar, which carries it.\n");

            return self::INVALID;
        }

        $server = new $this->serverClass();
        if (!method_exists($server, 'run')) {
            $output->writeError(\sprintf("%s is not a language server.\n", $this->serverClass));

            return self::INVALID;
        }

        $exitCode = $server->run();

        return \is_int($exitCode) ? $exitCode : self::FAILURE;
    }
}
