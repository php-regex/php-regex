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
use PHPRegex\LanguageServer\Protocol\FrameTrace;
use PHPRegex\LanguageServer\Protocol\InvalidMessage;
use PHPRegex\LanguageServer\Protocol\Message;
use PHPRegex\LanguageServer\Protocol\MessageLimits;
use PHPRegex\LanguageServer\Protocol\TraceLevel;
use PHPRegex\LanguageServer\Protocol\Transport;
use PHPRegex\Linter\Config\JudgedRange;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Exception\UnsupportedTargetException;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Parser\Engine\IniFlag;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;

/**
 * Language Server Protocol server for regex analysis.
 *
 * Provides real-time diagnostics, hover information, and code actions
 * for regex patterns in PHP source files.
 *
 * Patterns are judged for the workspace's target, resolved once at
 * "initialize" from the settings WorkspaceSettings reads. A Regex handed to
 * the constructor is used as it is.
 *
 * The calls read are those `regex lint` reads: the native functions, the
 * presets regex.json's "extraction.interop" names (every one by default) and
 * the functions its "extraction.functions" names. The functions and static
 * methods that declare a pattern parameter with #[RegexPattern] are read at
 * "initialize" too, from the PHP files under regex.json's "paths" (the root
 * by default) and out of its "exclude" entries (vendor/ by default), from
 * the libraries of vendor/ whatever "exclude" says, and from each open
 * document as it changes. A document in vendor/ is not diagnosed.
 *
 * @internal
 */
final class Server
{
    /**
     * The id of the watched-files registration, the one an unregister
     * request would name.
     */
    private const WATCHED_FILES_REGISTRATION = 'php-regex/did-change-watched-files';

    private bool $initialized = false;

    /**
     * How much of each frame the stderr frame log writes: set at
     * "initialize" from initializationOptions.trace, else the protocol's
     * "trace", and by "$/setTrace" after it.
     */
    private TraceLevel $trace = TraceLevel::Off;

    private bool $shutdown = false;

    /**
     * The code the session ends with, once the "exit" notification came.
     */
    private ?int $exitCode = null;

    /**
     * Whether the workspace's target is one the library does not judge:
     * then no pattern is.
     */
    private bool $refused = false;

    /**
     * The id the next request the server sends carries.
     */
    private int $nextRequestId = 1;

    /**
     * The watched-files registration request this session sent, never a
     * second one: its answer, a refusal included, is only logged.
     */
    private ?int $registrationRequestId = null;

    /**
     * Whether a stray client response was already traced on stderr.
     */
    private bool $strayResponseTraced = false;

    /**
     * Where the messages to the client are written.
     */
    private readonly Transport $transport;

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
     * @param MessageLimits|null       $limits       the caps the frames are
     *                                               read under, the production
     *                                               ones when null
     * @param Transport|null           $transport    where the messages to the
     *                                               client are written, stdout
     *                                               when null
     * @param resource|null            $errorStream  stream the error lines are
     *                                               written to, or null for
     *                                               stderr, where the editor
     *                                               collects the server log
     */
    public function __construct(
        private readonly ?Regex $givenRegex = null,
        private $input = null,
        ?PatternDeclarations $declarations = null,
        private readonly ?MessageLimits $limits = null,
        ?Transport $transport = null,
        private $errorStream = null,
    ) {
        $this->transport = $transport ?? new Transport();
        $this->documents = new DocumentManager(new RegexFinder(), $declarations ?? new PatternDeclarations());

        $this->initHandler = new InitializeHandler($this->transport);
        $this->completionHandler = new CompletionHandler($this->transport, $this->documents);
        $this->judgeWith($this->givenRegex ?? Regex::create());
    }

    /**
     * Run the LSP server main loop, until "exit" or the end of the input.
     * After "shutdown", every message but "exit" is left unserved.
     *
     * @return int the code to exit with: 1 on an "exit" no "shutdown" came before, 0 otherwise
     */
    public function run(): int
    {
        // Stdout carries the frames: PHP's own diagnostics go to stderr.
        // Where ini_get() or ini_set() is disabled the setting is left as
        // it is.
        $displayErrors = \function_exists('ini_get') && \function_exists('ini_set') ? \ini_get('display_errors') : false;
        if (false !== $displayErrors && IniFlag::displaysErrors($displayErrors)) {
            \ini_set('display_errors', 'stderr');
        }

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

        $this->transport->observeWith($this->traceOutbound(...), $this->writeErrorLine(...));

        try {
            return $this->serve($input);
        } finally {
            $this->transport->observeWith(null);
        }
    }

    /**
     * @param resource $input
     */
    private function serve($input): int
    {
        while (null === $this->exitCode) {
            $message = Message::readFrom($input, $this->limits);
            if (null === $message) {
                // EOF or read error
                break;
            }

            $this->traceInbound($message);

            // A message that cannot be used costs one error answer, not the
            // session.
            if ($message instanceof InvalidMessage) {
                $this->transport->error($message->id, $message->code, $message->message);

                continue;
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
     * Leave one line on stderr, where the editor collects the server log.
     */
    private function writeErrorLine(string $message): void
    {
        $line = "regex-lsp: {$message}\n";

        if (null !== $this->errorStream) {
            fwrite($this->errorStream, $line);
            fflush($this->errorStream);

            return;
        }

        // Uncovered by design: writing to the process's real stderr is
        // what tests must never do — one byte of it reads as a failure to
        // the tooling around the suite. The seam above is the tested path.
        file_put_contents('php://stderr', $line);
    }

    private function traceInbound(Message|InvalidMessage $message): void
    {
        $line = FrameTrace::inbound($message, $this->trace);
        if (null !== $line) {
            $this->writeErrorLine($line);
        }
    }

    private function traceOutbound(string $json): void
    {
        $line = FrameTrace::outbound($json, $this->trace);
        if (null !== $line) {
            $this->writeErrorLine($line);
        }
    }

    /**
     * "$/setTrace": the frame log's level from now on; a value that is not
     * one is ignored, with one line on stderr.
     */
    private function setTrace(Message $message): void
    {
        $value = $message->params['value'] ?? null;
        $level = \is_string($value) ? TraceLevel::tryFrom($value) : null;
        if (null === $level) {
            $this->writeErrorLine(\sprintf('$/setTrace value %s is not off, messages or verbose: ignored.', \is_string($value) ? '"'.$value.'"' : get_debug_type($value)));

            return;
        }

        $this->trace = $level;
    }

    /**
     * Answer a request whose handler failed, and leave a trace of every
     * failure on stderr, where the editor collects the server log.
     */
    private function reportFailure(Message $message, \Throwable $failure): void
    {
        $this->writeErrorLine(\sprintf('%s: %s', $failure::class, $failure->getMessage()));

        if ($message->isRequest() && null !== $message->id) {
            $this->transport->error($message->id, Transport::INTERNAL_ERROR, $failure->getMessage());
        }
    }

    /**
     * Handle a single LSP message.
     */
    private function handleMessage(Message $message): void
    {
        $method = $message->method;
        if (null === $method) {
            $this->handleClientResponse($message);

            return;
        }

        // Exit notification: the protocol's code, the binary exits with it.
        if ('exit' === $method) {
            $this->exitCode = $this->shutdown ? 0 : 1;

            return;
        }

        // Shut down: nothing is served until "exit", a request, a second
        // shutdown included, is answered Invalid Request.
        if ($this->shutdown) {
            if (null !== $message->id) {
                $this->transport->error($message->id, InvalidMessage::INVALID_REQUEST, 'The server is shut down');
            }

            return;
        }

        if ('shutdown' === $method) {
            $this->shutdown = true;
            if (null !== $message->id) {
                $this->transport->success($message->id, null);
            }

            return;
        }

        // A second "initialize" judges nothing again: a request is
        // answered Invalid Request, a notification dropped with one line
        // on stderr.
        if ('initialize' === $method) {
            if ($this->initialized) {
                if (null !== $message->id) {
                    $this->transport->error($message->id, InvalidMessage::INVALID_REQUEST, 'The server is already initialized.');
                } else {
                    $this->writeErrorLine('the "initialize" notification was dropped: the server is already initialized.');
                }

                return;
            }

            $this->handleInitialize($message);

            return;
        }

        // Handle initialized notification: the files the server reads are
        // registered with a client that offers to watch them itself. A
        // stray one that no initialize served — the initialize request
        // sets the flag itself — must not poison the session: it is
        // dropped like any notification before initialize.
        if ('initialized' === $method) {
            if (!$this->initialized) {
                $this->writeErrorLine('the "initialized" notification was dropped: the server is not initialized.');

                return;
            }

            $this->registerWatchedFiles();

            return;
        }

        // Before initialization nothing is served: a request is refused,
        // a notification dropped — the "$/" ones silently, like the
        // unknown notifications they are to this server.
        if (!$this->initialized) {
            if (null !== $message->id) {
                $this->transport->error($message->id, -32002, 'Server not initialized');

                return;
            }

            if (!str_starts_with($method, '$/')) {
                $this->writeErrorLine(\sprintf('the "%s" notification was dropped: the server is not initialized.', $method));
            }

            return;
        }

        // A workspace the library does not judge gets no verdict: a request
        // that asks for one is answered with none.
        if ($this->refused && \in_array($method, ['textDocument/didOpen', 'textDocument/didChange', 'textDocument/didClose', 'textDocument/didSave', 'workspace/didChangeWatchedFiles', 'textDocument/hover', 'textDocument/codeAction'], true)) {
            if ($message->isRequest() && null !== $message->id) {
                $this->transport->success($message->id, 'textDocument/codeAction' === $method ? [] : null);
            }

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
            '$/setTrace' => $this->setTrace($message),
            '$/cancelRequest' => null, // Ignore cancellation
            default => $this->handleUnknownMethod($message),
        };
    }

    private function handleInitialize(Message $message): void
    {
        // The frame log starts with the request that turns it on.
        $params = $message->params ?? [];
        $this->trace = WorkspaceSettings::traceLevel($params, $this->transport);
        $this->traceInbound($message);

        $this->initHandler->handle($message);
        $this->initialized = true;

        $workspace = WorkspaceSettings::read($params, $this->transport);
        // The lint rules regex.json turns on or off, as `regex lint` reads them.
        $arguments = $workspace->lintArguments();
        $lintRules = $arguments->lintRules;
        // The calls read, as `regex lint` reads them.
        $this->documents->readWith(new RegexFinder(PatternFunctionRegistry::create($arguments->interop, $arguments->patternFunctions)));
        [$redosMode, $redosThreshold] = $workspace->redos($arguments);

        if (null !== $this->givenRegex) {
            $target = $this->givenRegex->target();
            $this->log(Transport::LOG_INFO, \sprintf('Target: PHP %s, PCRE2 %s%s (the Regex the server was started with)', ProjectTarget::phpLabel($target->phpVersionId), $target->pcreVersion, 'C' === $target->locale ? '' : ', locale '.$target->locale));
            $this->judgeWith($this->givenRegex, $lintRules, $redosMode, $redosThreshold, $arguments->reportUnmatchedIgnores);
        } else {
            try {
                $target = $workspace->target();
            } catch (UnsupportedTargetException|InvalidRegexOptionException $e) {
                $message = $e->getMessage().' No pattern is judged.';
                $this->log(Transport::LOG_ERROR, $message);
                $this->transport->notification('window/showMessage', ['type' => Transport::LOG_ERROR, 'message' => $message]);
                $this->refused = true;

                return;
            }
            foreach ($target->notices() as $notice) {
                $this->log(Transport::LOG_INFO, $notice);
            }
            $this->log(Transport::LOG_INFO, \sprintf('Target: %s (%s)', $target->label(), $target->source()));

            $regex = Regex::create($target->regexOptions());
            $this->judgeWith($regex, $lintRules, $redosMode, $redosThreshold, $arguments->reportUnmatchedIgnores, $target->judgedRange($regex->parser()));
        }

        if (null !== $workspace->root) {
            $this->readDeclarations($workspace->root, $workspace);
        }
    }

    /**
     * Ask a client that offers dynamic registration to watch the files the
     * server reads: the workspace's PHP files and Composer's manifest. One
     * request a session: its answer, a refusal included, is never retried.
     */
    private function registerWatchedFiles(): void
    {
        if ($this->refused || !$this->initHandler->watchedFilesRegistration() || null !== $this->registrationRequestId) {
            return;
        }

        $this->registrationRequestId = $this->nextRequestId++;
        $this->transport->request($this->registrationRequestId, 'client/registerCapability', [
            'registrations' => [
                [
                    'id' => self::WATCHED_FILES_REGISTRATION,
                    'method' => 'workspace/didChangeWatchedFiles',
                    'registerOptions' => [
                        'watchers' => [
                            ['globPattern' => '**/*.php'],
                            ['globPattern' => 'vendor/composer/installed.json'],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * A message with an id and no method is a response to a request the
     * server sent: the watched-files registration. Whatever it answers, it
     * costs one line on stderr — a refusal included — and asks for nothing
     * again; an id this session never chose is ignored the same way.
     */
    private function handleClientResponse(Message $message): void
    {
        $id = $message->id;

        if (null !== $this->registrationRequestId && $id === $this->registrationRequestId) {
            $this->writeErrorLine($message->isErrorResponse()
                ? \sprintf('warning: the watched-files registration (request %d) was refused by the client: nothing is registered again, the editor sends no file events.', $this->registrationRequestId)
                : \sprintf('the watched-files registration (request %d) was answered: nothing is registered again.', $this->registrationRequestId));

            return;
        }

        // The first stray response says the channel is noisy; the rest
        // would only flood the editor's log panel.
        if ($this->strayResponseTraced) {
            return;
        }

        $this->strayResponseTraced = true;

        $this->writeErrorLine(null === $id
            ? 'a response with no id the server did not ask for was ignored.'
            : \sprintf('a response with the id %s the server did not ask for was ignored.', \is_int($id) ? (string) $id : $id));
    }

    /**
     * Reads the workspace for the functions that declare a pattern parameter,
     * under the "paths" and out of the "exclude" entries `regex lint` reads,
     * and the libraries of vendor/, which `regex lint` reads whatever
     * "exclude" says.
     */
    private function readDeclarations(string $root, WorkspaceSettings $workspace): void
    {
        $paths = $workspace->paths();
        $exclude = $workspace->exclude();

        $declarations = $this->documents->declarations();
        if (!$declarations->scanWorkspace($root, [] === $paths ? ['.'] : $paths, $exclude)) {
            $this->warn('Functions declaring #[RegexPattern] were read from the first PHP files of the workspace only: narrow "paths" or "exclude" in regex.json to read them all.');
        }

        // Read even when absent: the directory is watched, and read once
        // Composer installs into it.
        $libraries = $declarations->scanLibraries($root.'/vendor');
        if (!is_dir($root.'/vendor') && self::requiresPackages($root.'/composer.json')) {
            $this->warn('vendor/ is not in the workspace, though composer.json is: the functions its libraries declare with #[RegexPattern] are not read, and the patterns passed to them are not checked. Install the dependencies where the editor sees them, the host and not only a container.');
        } elseif (!$libraries) {
            $this->warn('Functions declaring #[RegexPattern] were read from the first PHP files of vendor/ only.');
        }
    }

    /**
     * Whether the manifest requires a package Composer installs into
     * vendor/: a platform requirement (php, hhvm, ext-*, lib-*, composer and its APIs)
     * installs nothing, and a manifest that cannot be read requires
     * nothing that can be told.
     */
    private static function requiresPackages(string $manifest): bool
    {
        $content = is_file($manifest) ? file_get_contents($manifest) : false;
        $decoded = false === $content ? null : json_decode($content, true);
        if (!\is_array($decoded)) {
            return false;
        }

        foreach (['require', 'require-dev'] as $section) {
            $requirements = $decoded[$section] ?? null;
            foreach (\is_array($requirements) ? array_keys($requirements) : [] as $name) {
                if (\is_string($name) && 1 !== LibraryPcre::match('/^(?:php(?:-64bit|-ipv6|-zts|-debug)?|hhvm|ext-.+|lib-.+|composer(?:-plugin-api|-runtime-api)?)$/i', $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A coverage the session reads less of than the user expects: logged,
     * and shown once, since "initialize" reads the workspace once.
     */
    private function warn(string $message): void
    {
        $this->log(Transport::LOG_WARNING, $message);
        $this->transport->notification('window/showMessage', ['type' => Transport::LOG_WARNING, 'message' => $message]);
    }

    /**
     * @param array<string, bool> $lintRules              the lint rules turned on or off by id
     * @param bool                $reportUnmatchedIgnores whether a marker that silences nothing is a warning
     * @param JudgedRange|null    $judged                 the versions the project is judged for, for a
     *                                                    "@regex-target" marker to narrow
     */
    private function judgeWith(Regex $regex, array $lintRules = [], RedosMode $redosMode = RedosMode::Off, RedosSeverity $redosThreshold = RedosSeverity::High, bool $reportUnmatchedIgnores = true, ?JudgedRange $judged = null): void
    {
        $this->textDocHandler = new TextDocumentHandler($this->transport, $this->documents, $regex, $lintRules, $redosMode, $redosThreshold, $reportUnmatchedIgnores, $judged);
        $this->codeActionHandler = new CodeActionHandler($this->transport, $this->documents, $regex, $lintRules);
    }

    private function log(int $type, string $message): void
    {
        $this->transport->notification('window/logMessage', ['type' => $type, 'message' => $message]);
    }

    private function handleUnknownMethod(Message $message): void
    {
        // Only respond to requests, not notifications
        if ($message->isRequest() && null !== $message->id) {
            $this->transport->error(
                $message->id,
                -32601, // Method not found
                "Method not found: {$message->method}",
            );
        }
    }
}
