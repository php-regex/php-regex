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

namespace PHPRegex\LanguageServer;

use PHPRegex\LanguageServer\Document\DocumentManager;
use PHPRegex\LanguageServer\Document\PatternDeclarations;
use PHPRegex\LanguageServer\Document\RegexFinder;
use PHPRegex\LanguageServer\Handler\CodeActionHandler;
use PHPRegex\LanguageServer\Handler\CompletionHandler;
use PHPRegex\LanguageServer\Handler\InitializeHandler;
use PHPRegex\LanguageServer\Handler\TextDocumentHandler;
use PHPRegex\LanguageServer\Protocol\Message;
use PHPRegex\LanguageServer\Protocol\Response;
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Toolkit\Regex;

/**
 * Language Server Protocol server for regex analysis.
 *
 * Provides real-time diagnostics, hover information, and code actions
 * for regex patterns in PHP source files.
 *
 * Patterns are judged for the workspace's target, resolved once at
 * "initialize" for the first workspace folder, else rootUri:
 * initializationOptions.phpVersion / pcreVersion, then regex.json there, then
 * composer.json there, then the running PHP. A Regex handed to the
 * constructor is used as it is.
 *
 * The functions and static methods that declare a pattern parameter with
 * #[RegexPattern] are read then too, from the PHP files under regex.json's
 * "paths" (the root by default) and out of its "exclude" entries (vendor/ by
 * default), and from each open document as it changes.
 *
 * @internal
 */
final class Server
{
    /**
     * JSON-RPC "internal error".
     */
    private const ERROR_INTERNAL = -32603;

    /**
     * window/logMessage types.
     */
    private const LOG_WARNING = 2;
    private const LOG_INFO = 3;

    private bool $initialized = false;

    private bool $shutdown = false;

    /**
     * The code the session ends with, once the "exit" notification came.
     */
    private ?int $exitCode = null;

    private readonly InitializeHandler $initHandler;

    private readonly DocumentManager $documents;

    private TextDocumentHandler $textDocHandler;

    private CodeActionHandler $codeActionHandler;

    private readonly CompletionHandler $completionHandler;

    /**
     * @param Regex|null               $givenRegex   judges every pattern; null judges for the
     *                                               workspace's target
     * @param resource|null            $input        stream the messages are read from, or null
     *                                               for stdin
     * @param PatternDeclarations|null $declarations the declarations the
     *                                               workspace is read into
     */
    public function __construct(
        private readonly ?Regex $givenRegex = null,
        private $input = null,
        ?PatternDeclarations $declarations = null
    ) {
        $this->documents = new DocumentManager(new RegexFinder(), $declarations ?? new PatternDeclarations());

        $this->initHandler = new InitializeHandler();
        $this->completionHandler = new CompletionHandler($this->documents);
        $this->judgeWith($this->givenRegex ?? Regex::create());
    }

    /**
     * Run the LSP server main loop, until "shutdown", "exit" or the end of
     * the input.
     *
     * @return int the code to exit with: 1 on an "exit" no "shutdown" came before, 0 otherwise
     */
    public function run(): int
    {
        // STDIN is only defined when PHP runs a script from the command line
        // with its standard streams: a child PHP process may have to open it.
        $input = $this->input ?? (\defined('STDIN') ? \STDIN : fopen('php://stdin', 'r'));
        if (false === $input) {
            return 1;
        }

        // Unbuffered: a message is read as soon as it arrives.
        if (\function_exists('stream_set_read_buffer')) {
            stream_set_read_buffer($input, 0);
        }

        while (!$this->shutdown && null === $this->exitCode) {
            $message = Message::readFrom($input);
            if (null === $message) {
                // EOF or read error
                break;
            }

            // A pattern that trips a limit, or any other failure inside a
            // handler, must cost the editor one answer — not the session.
            try {
                $this->handleMessage($message);
            } catch (\Throwable $failure) {
                $this->reportFailure($message, $failure);
            }
        }

        return $this->exitCode ?? 0;
    }

    /**
     * Answer a request whose handler failed, and leave a trace of a failed
     * notification on stderr, where the editor collects the server log.
     */
    private function reportFailure(Message $message, \Throwable $failure): void
    {
        if ($message->isRequest() && null !== $message->id) {
            Response::error($message->id, self::ERROR_INTERNAL, $failure->getMessage());

            return;
        }

        file_put_contents(
            'php://stderr',
            \sprintf("regex-lsp: %s: %s\n", $failure::class, $failure->getMessage()),
        );
    }

    /**
     * Handle a single LSP message.
     */
    private function handleMessage(Message $message): void
    {
        $method = $message->method;
        if (null === $method) {
            return;
        }

        // Special handling for shutdown
        if ('shutdown' === $method) {
            $this->shutdown = true;
            if (null !== $message->id) {
                Response::success($message->id, null);
            }

            return;
        }

        // Exit notification: the protocol's code, the binary exits with it.
        if ('exit' === $method) {
            $this->exitCode = $this->shutdown ? 0 : 1;

            return;
        }

        // Handle initialize before anything else
        if ('initialize' === $method) {
            $this->handleInitialize($message);

            return;
        }

        // Handle initialized notification
        if ('initialized' === $method) {
            $this->initialized = true;

            return;
        }

        // Reject requests before initialization (except shutdown)
        if (!$this->initialized && $message->isRequest() && null !== $message->id) {
            Response::error($message->id, -32002, 'Server not initialized');

            return;
        }

        // Handle document methods
        match ($method) {
            'textDocument/didOpen' => $this->textDocHandler->didOpen($message),
            'textDocument/didChange' => $this->textDocHandler->didChange($message),
            'textDocument/didClose' => $this->textDocHandler->didClose($message),
            'textDocument/didSave' => $this->textDocHandler->didSave($message),
            'workspace/didChangeWatchedFiles' => $this->textDocHandler->didChangeWatchedFiles($message),
            'textDocument/hover' => $this->textDocHandler->hover($message),
            'textDocument/codeAction' => $this->codeActionHandler->handle($message),
            'textDocument/completion' => $this->completionHandler->handle($message),
            '$/cancelRequest' => null, // Ignore cancellation
            default => $this->handleUnknownMethod($message),
        };
    }

    private function handleInitialize(Message $message): void
    {
        $this->initHandler->handle($message);
        $this->initialized = true;

        $params = $message->params ?? [];
        $root = self::rootDirectory($params);
        $config = null === $root ? [] : self::loadConfig($root);

        if (null !== $this->givenRegex) {
            $target = $this->givenRegex->target();
            self::log(self::LOG_INFO, \sprintf('Target: PHP %s, PCRE2 %s (the Regex the server was started with)', ProjectTarget::phpLabel($target->phpVersionId), $target->pcreVersion));
        } else {
            $target = $this->resolveTarget($params, $root, $config);
            foreach ($target->notices() as $notice) {
                self::log(self::LOG_INFO, $notice);
            }
            self::log(self::LOG_INFO, \sprintf('Target: PHP %s, PCRE2 %s (%s)', $target->php(), $target->target()->pcreVersion, $target->source()));

            $this->judgeWith(Regex::create($target->regexOptions()));
        }

        if (null !== $root) {
            $this->readDeclarations($root, $config);
        }
    }

    /**
     * regex.json at the root; what cannot be read is a warning, and no
     * configuration.
     *
     * @return array<string, mixed>
     */
    private static function loadConfig(string $root): array
    {
        $loaded = (new LintConfigLoader())->load($root);
        if (null !== $loaded->error) {
            self::log(self::LOG_WARNING, 'regex.json ignored: '.$loaded->error);

            return [];
        }

        return $loaded->config;
    }

    /**
     * Reads the workspace for the functions that declare a pattern parameter,
     * under the "paths" and out of the "exclude" entries `regex lint` reads.
     *
     * @param array<string, mixed> $config
     */
    private function readDeclarations(string $root, array $config): void
    {
        $paths = self::stringList($config['paths'] ?? null);
        $exclude = \array_key_exists('exclude', $config) ? self::stringList($config['exclude']) : ['vendor'];

        $declarations = $this->documents->declarations();
        if (!$declarations->scanWorkspace($root, [] === $paths ? ['.'] : $paths, $exclude)) {
            self::log(self::LOG_WARNING, 'Functions declaring #[RegexPattern] were read from the first PHP files of the workspace only: narrow "paths" or "exclude" in regex.json to read them all.');
        }
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (\is_string($value)) {
            return [$value];
        }

        return \is_array($value) ? array_values(array_filter($value, \is_string(...))) : [];
    }

    /**
     * @param array<string, mixed> $params the "initialize" params
     * @param array<string, mixed> $config regex.json
     */
    private function resolveTarget(array $params, ?string $root, array $config): ProjectTarget
    {
        $options = \is_array($params['initializationOptions'] ?? null) ? $params['initializationOptions'] : [];

        $configPhp = $config['phpVersion'] ?? null;
        $configPcre = $config['pcreVersion'] ?? null;
        $pcre = self::readVersion($options, 'pcreVersion', 'pcre_version');

        // regex.json was checked against its schema when it was loaded.
        return ProjectTarget::fromSources(
            [
                'initializationOptions' => self::readVersion($options, 'phpVersion', 'php_version'),
                'regex.json' => \is_string($configPhp) || \is_int($configPhp) ? $configPhp : null,
            ],
            [
                'initializationOptions' => \is_string($pcre) ? $pcre : null,
                'regex.json' => \is_string($configPcre) ? $configPcre : null,
            ],
            $root,
            getenv(),
        );
    }

    /**
     * An initializationOptions version, or null when it is unset or cannot
     * be read: what cannot be read is a warning, and the next source is
     * used.
     *
     * @param array<array-key, mixed> $options
     */
    private static function readVersion(array $options, string $key, string $regexOption): string|int|null
    {
        $value = $options[$key] ?? null;
        if (null === $value) {
            return null;
        }

        if (\is_string($value) || \is_int($value)) {
            try {
                ParserOptions::fromArray([$regexOption => $value]);

                return $value;
            } catch (InvalidRegexOptionException) {
                // Reported below, like a value of the wrong type.
            }
        }

        self::log(self::LOG_WARNING, \sprintf(
            'initializationOptions.%s %s is not a version: ignored.',
            $key,
            \is_string($value) || \is_int($value) ? '"'.$value.'"' : get_debug_type($value),
        ));

        return null;
    }

    /**
     * The directory of the first workspace folder, else of rootUri; null
     * when neither is a local directory.
     *
     * @param array<string, mixed> $params
     */
    private static function rootDirectory(array $params): ?string
    {
        $folders = $params['workspaceFolders'] ?? null;
        $uri = \is_array($folders) && \is_array($folders[0] ?? null) ? ($folders[0]['uri'] ?? null) : null;
        $uri ??= $params['rootUri'] ?? null;

        if (!\is_string($uri) || !str_starts_with($uri, 'file://')) {
            return null;
        }

        // parse_url() reads file:///C:/project as C:/project.
        $path = rawurldecode((string) parse_url($uri, \PHP_URL_PATH));

        return is_dir($path) ? $path : null;
    }

    private function judgeWith(Regex $regex): void
    {
        $this->textDocHandler = new TextDocumentHandler($this->documents, $regex);
        $this->codeActionHandler = new CodeActionHandler($this->documents, $regex);
    }

    private static function log(int $type, string $message): void
    {
        Response::notification('window/logMessage', ['type' => $type, 'message' => $message]);
    }

    private function handleUnknownMethod(Message $message): void
    {
        // Only respond to requests, not notifications
        if ($message->isRequest() && null !== $message->id) {
            Response::error(
                $message->id,
                -32601, // Method not found
                "Method not found: {$message->method}",
            );
        }
    }
}
