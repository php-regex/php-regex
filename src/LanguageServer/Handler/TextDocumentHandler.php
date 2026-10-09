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

namespace PHPRegex\LanguageServer\Handler;

use PHPRegex\Explain\TextExplainer;
use PHPRegex\LanguageServer\Converter\DiagnosticConverter;
use PHPRegex\LanguageServer\Document\DocumentManager;
use PHPRegex\LanguageServer\Document\PatternDeclarations;
use PHPRegex\LanguageServer\Protocol\Message;
use PHPRegex\LanguageServer\Protocol\Response;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Toolkit\Regex;

/**
 * Handles text document notifications and requests.
 *
 * @internal
 */
final readonly class TextDocumentHandler
{
    /**
     * FileChangeType.Deleted.
     */
    private const FILE_DELETED = 3;

    private DiagnosticConverter $diagnosticConverter;

    public function __construct(private DocumentManager $documents, private Regex $regex)
    {
        $this->diagnosticConverter = new DiagnosticConverter();
    }

    /**
     * Handle textDocument/didOpen notification.
     */
    public function didOpen(Message $message): void
    {
        $params = $message->params ?? [];
        /** @var array<string, mixed> $textDocument */
        $textDocument = $params['textDocument'] ?? [];
        $uri = isset($textDocument['uri']) && \is_string($textDocument['uri']) ? $textDocument['uri'] : null;
        $text = isset($textDocument['text']) && \is_string($textDocument['text']) ? $textDocument['text'] : null;

        if (null === $uri || null === $text) {
            return;
        }

        $this->publishDiagnostics($uri, ...$this->documents->open($uri, $text));
    }

    /**
     * Handle textDocument/didChange notification.
     */
    public function didChange(Message $message): void
    {
        $params = $message->params ?? [];
        /** @var array<string, mixed> $textDocument */
        $textDocument = $params['textDocument'] ?? [];
        $uri = isset($textDocument['uri']) && \is_string($textDocument['uri']) ? $textDocument['uri'] : null;
        /** @var array<int, array{text?: string}> $contentChanges */
        $contentChanges = isset($params['contentChanges']) && \is_array($params['contentChanges']) ? $params['contentChanges'] : [];

        if (null === $uri || [] === $contentChanges) {
            return;
        }

        // For full sync, we get the complete text
        $text = isset($contentChanges[0]['text']) && \is_string($contentChanges[0]['text']) ? $contentChanges[0]['text'] : null;
        if (null === $text) {
            return;
        }

        $this->publishDiagnostics($uri, ...$this->documents->update($uri, $text));
    }

    /**
     * Handle textDocument/didClose notification.
     */
    public function didClose(Message $message): void
    {
        $params = $message->params ?? [];
        /** @var array<string, mixed> $textDocument */
        $textDocument = $params['textDocument'] ?? [];
        $uri = isset($textDocument['uri']) && \is_string($textDocument['uri']) ? $textDocument['uri'] : null;

        if (null === $uri) {
            return;
        }

        $affected = $this->documents->close($uri);

        // Clear diagnostics
        Response::notification('textDocument/publishDiagnostics', [
            'uri' => $uri,
            'diagnostics' => [],
        ]);

        $this->publishDiagnostics(...$affected);
    }

    /**
     * Handle textDocument/didSave notification: the saved text holds the
     * declarations of its file once the document is closed.
     */
    public function didSave(Message $message): void
    {
        $params = $message->params ?? [];
        /** @var array<string, mixed> $textDocument */
        $textDocument = $params['textDocument'] ?? [];
        $uri = isset($textDocument['uri']) && \is_string($textDocument['uri']) ? $textDocument['uri'] : null;
        $path = null === $uri ? null : PatternDeclarations::pathOf($uri);
        $text = isset($params['text']) && \is_string($params['text']) ? $params['text'] : null;

        // The open document stands for its file until it is closed: nothing
        // is checked again before then.
        if (null !== $path) {
            $this->documents->declarations()->readFile($path, $text);
        }
    }

    /**
     * Handle workspace/didChangeWatchedFiles notification: a file created,
     * changed or deleted outside the editor.
     */
    public function didChangeWatchedFiles(Message $message): void
    {
        $changes = $message->params['changes'] ?? null;
        if (!\is_array($changes)) {
            return;
        }

        $changed = false;
        foreach ($changes as $change) {
            $uri = \is_array($change) && \is_string($change['uri'] ?? null) ? $change['uri'] : null;
            $path = null === $uri ? null : PatternDeclarations::pathOf($uri);
            if (null === $path) {
                continue;
            }

            $declarations = $this->documents->declarations();
            $changed = (self::FILE_DELETED === ($change['type'] ?? null) ? $declarations->forgetFile($path) : $declarations->readFile($path)) || $changed;
        }

        if ($changed) {
            $this->publishDiagnostics(...$this->documents->refresh());
        }
    }

    /**
     * Handle textDocument/hover request.
     */
    public function hover(Message $message): void
    {
        $params = $message->params ?? [];
        /** @var array<string, mixed> $textDocument */
        $textDocument = $params['textDocument'] ?? [];
        $uri = isset($textDocument['uri']) && \is_string($textDocument['uri']) ? $textDocument['uri'] : null;
        /** @var array{line?: int, character?: int}|null $position */
        $position = isset($params['position']) && \is_array($params['position']) ? $params['position'] : null;

        if (null === $message->id || null === $uri || null === $position) {
            return;
        }

        $line = $position['line'] ?? 0;
        $character = $position['character'] ?? 0;

        $occurrence = $this->documents->getOccurrenceAtPosition($uri, $line, $character);
        if (null === $occurrence) {
            Response::success($message->id, null);

            return;
        }

        try {
            $ast = $this->regex->parse($occurrence->pattern);
            $explainer = new TextExplainer();
            $explanation = $ast->accept($explainer);

            $markdown = "**Regex Pattern**\n\n```\n{$occurrence->pattern}\n```\n\n**Explanation**\n\n{$explanation}";

            Response::success($message->id, [
                'contents' => [
                    'kind' => 'markdown',
                    'value' => $markdown,
                ],
                'range' => [
                    'start' => $occurrence->start,
                    'end' => $occurrence->end,
                ],
            ]);
        } catch (LexerException|ParserException $e) {
            Response::success($message->id, [
                'contents' => [
                    'kind' => 'markdown',
                    'value' => "**Regex Error**\n\n{$e->getMessage()}",
                ],
            ]);
        }
    }

    /**
     * Publish diagnostics for documents.
     */
    private function publishDiagnostics(string ...$uris): void
    {
        foreach ($uris as $uri) {
            $this->publishDocument($uri);
        }
    }

    private function publishDocument(string $uri): void
    {
        $diagnostics = [];

        foreach ($this->documents->getOccurrences($uri) as $occurrence) {
            // Offsets count from the pattern body: past the quote and the
            // delimiter the finder's patterns open with. The body runs to
            // the end of the pattern, modifiers included.
            $bodyStart = [
                'line' => $occurrence->start['line'],
                'character' => $occurrence->start['character'] + 2,
            ];
            $bodyLength = \strlen($occurrence->pattern) - 1;

            try {
                $ast = $this->regex->parse($occurrence->pattern);

                // A pattern PCRE refuses gets that error, and no lint issue.
                $validation = $this->regex->validate($occurrence->pattern);
                if (!$validation->isValid && null !== $validation->errorCode) {
                    $diagnostics[] = $this->diagnosticConverter->fromValidationError(
                        $validation->error ?? 'Invalid regex.',
                        $validation->errorCode,
                        $bodyStart,
                        $bodyLength,
                        $validation->offset,
                    );

                    continue;
                }

                // Run linter
                $linter = new PatternLinter();
                $ast->accept($linter);

                foreach ($linter->getIssues() as $issue) {
                    $diagnostics[] = $this->diagnosticConverter->convert(
                        $issue,
                        $bodyStart,
                        $bodyLength,
                    );
                }
            } catch (LexerException|ParserException $e) {
                $diagnostics[] = $this->diagnosticConverter->fromParseError(
                    $e->getMessage(),
                    $e->getErrorCode(),
                    $bodyStart,
                    $bodyLength,
                    $e->position,
                );
            }
        }

        Response::notification('textDocument/publishDiagnostics', [
            'uri' => $uri,
            'diagnostics' => $diagnostics,
        ]);
    }
}
