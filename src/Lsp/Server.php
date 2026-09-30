<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Lsp;

use RegexParser\Exception\InvalidRegexOptionException;
use RegexParser\Lint\Command\LintConfigLoader;
use RegexParser\Lint\Command\ProjectTarget;
use RegexParser\Lsp\Document\DocumentManager;
use RegexParser\Lsp\Document\RegexFinder;
use RegexParser\Lsp\Handler\CodeActionHandler;
use RegexParser\Lsp\Handler\CompletionHandler;
use RegexParser\Lsp\Handler\InitializeHandler;
use RegexParser\Lsp\Handler\TextDocumentHandler;
use RegexParser\Lsp\Protocol\Message;
use RegexParser\Lsp\Protocol\Response;
use RegexParser\Regex;
use RegexParser\RegexOptions;

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
     * The Regex the server was given, which the workspace does not replace.
     */
    private readonly ?Regex $givenRegex;

    /**
     * @param Regex|null    $regex judges every pattern; null judges for the
     *                             workspace's target
     * @param resource|null $input stream the messages are read from, or null
     *                             for stdin
     */
    public function __construct(?Regex $regex = null, private $input = null)
    {
        $this->givenRegex = $regex;
        $this->documents = new DocumentManager(new RegexFinder());

        $this->initHandler = new InitializeHandler();
        $this->completionHandler = new CompletionHandler($this->documents);
        $this->judgeWith($regex ?? Regex::create());
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
            'textDocument/didSave' => null, // Optional, we handle on change
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

        if (null !== $this->givenRegex) {
            $target = $this->givenRegex->target();
            self::log(self::LOG_INFO, \sprintf(
                'Target: PHP %d.%d, PCRE2 %s (the Regex the server was started with)',
                intdiv($target->phpVersionId, 10000),
                intdiv($target->phpVersionId, 100) % 100,
                $target->pcreVersion,
            ));

            return;
        }

        $target = $this->resolveTarget($message->params ?? []);
        foreach ($target->notices() as $notice) {
            self::log(self::LOG_INFO, $notice);
        }
        self::log(self::LOG_INFO, \sprintf('Target: PHP %s, PCRE2 %s (%s)', $target->php(), $target->target()->pcreVersion, $target->source()));

        $this->judgeWith(Regex::create($target->regexOptions()));
    }

    /**
     * @param array<string, mixed> $params the "initialize" params
     */
    private function resolveTarget(array $params): ProjectTarget
    {
        $root = self::rootDirectory($params);
        $options = \is_array($params['initializationOptions'] ?? null) ? $params['initializationOptions'] : [];

        $config = [];
        if (null !== $root) {
            $loaded = (new LintConfigLoader())->load($root);
            if (null === $loaded->error) {
                $config = $loaded->config;
            } else {
                self::log(self::LOG_WARNING, 'regex.json ignored: '.$loaded->error);
            }
        }

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
                RegexOptions::fromArray([$regexOption => $value]);

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
