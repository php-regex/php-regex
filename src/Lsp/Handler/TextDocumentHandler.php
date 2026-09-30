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

namespace RegexParser\Lsp\Handler;

use RegexParser\Exception\LexerException;
use RegexParser\Exception\ParserException;
use RegexParser\Lsp\Converter\DiagnosticConverter;
use RegexParser\Lsp\Document\DocumentManager;
use RegexParser\Lsp\Protocol\Message;
use RegexParser\Lsp\Protocol\Response;
use RegexParser\NodeVisitor\ExplainNodeVisitor;
use RegexParser\NodeVisitor\LinterNodeVisitor;
use RegexParser\Regex;

/**
 * Handles text document notifications and requests.
 */
final readonly class TextDocumentHandler
{
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

        $this->documents->open($uri, $text);
        $this->publishDiagnostics($uri);
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

        $this->documents->update($uri, $text);
        $this->publishDiagnostics($uri);
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

        $this->documents->close($uri);

        // Clear diagnostics
        Response::notification('textDocument/publishDiagnostics', [
            'uri' => $uri,
            'diagnostics' => [],
        ]);
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
            $explainer = new ExplainNodeVisitor();
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
     * Publish diagnostics for a document.
     */
    private function publishDiagnostics(string $uri): void
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

                // Run linter
                $linter = new LinterNodeVisitor();
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
