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

use RegexParser\Lsp\Document\DocumentManager;
use RegexParser\Lsp\Document\RegexFinder;
use RegexParser\Lsp\Handler\CodeActionHandler;
use RegexParser\Lsp\Handler\CompletionHandler;
use RegexParser\Lsp\Handler\InitializeHandler;
use RegexParser\Lsp\Handler\TextDocumentHandler;
use RegexParser\Lsp\Protocol\Message;
use RegexParser\Lsp\Protocol\Response;
use RegexParser\Regex;

/**
 * Language Server Protocol server for regex analysis.
 *
 * Provides real-time diagnostics, hover information, and code actions
 * for regex patterns in PHP source files.
 */
final class Server
{
    /**
     * JSON-RPC "internal error".
     */
    private const ERROR_INTERNAL = -32603;

    private bool $initialized = false;

    private bool $shutdown = false;

    /**
     * The code the session ends with, once the "exit" notification came.
     */
    private ?int $exitCode = null;

    private readonly InitializeHandler $initHandler;

    private readonly TextDocumentHandler $textDocHandler;

    private readonly CodeActionHandler $codeActionHandler;

    private readonly CompletionHandler $completionHandler;

    /**
     * @param resource|null $input stream the messages are read from, or null
     *                             for stdin
     */
    public function __construct(?Regex $regex = null, private $input = null)
    {
        $regex ??= Regex::create();
        $finder = new RegexFinder();
        $documents = new DocumentManager($finder);

        $this->initHandler = new InitializeHandler();
        $this->textDocHandler = new TextDocumentHandler($documents, $regex);
        $this->codeActionHandler = new CodeActionHandler($documents, $regex);
        $this->completionHandler = new CompletionHandler($documents);
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
