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

use PHPRegex\LanguageServer\Document\DocumentManager;
use PHPRegex\LanguageServer\Document\RegexOccurrence;
use PHPRegex\LanguageServer\Protocol\Message;
use PHPRegex\LanguageServer\Protocol\Transport;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Parser\Exception\FormattingException;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\LimitException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Printer\FormatResult;
use PHPRegex\Parser\Printer\PatternFormatter;
use PHPRegex\Toolkit\Regex;

/**
 * Handles textDocument/codeAction requests.
 *
 * A code action: a rewrite of the literal, or the quick fix of the
 * diagnostics it answers.
 *
 * @phpstan-type RewriteAction array{
 *     title: string,
 *     kind: 'refactor.rewrite',
 *     edit: array{changes: array<string, list<array{range: Range, newText: string}>>},
 * }
 * @phpstan-type CodeAction RewriteAction|array{
 *     title: string,
 *     kind: 'quickfix',
 *     diagnostics: list<array{code?: int|string|null}>,
 *     isPreferred: true,
 *     edit: array{changes: array<string, list<array{range: Range, newText: string}>>},
 * }
 *
 * @phpstan-import-type Position from RegexOccurrence
 * @phpstan-import-type Range from RegexOccurrence
 *
 * @internal
 */
final class CodeActionHandler
{
    /**
     * Unicode-related lint IDs that can be fixed by adding /u flag.
     */
    private const UNICODE_LINT_IDS = [
        'regex.lint.unicode.shorthandWithoutU',
        'regex.lint.unicode.propertyWithoutU',
        'regex.lint.unicode.bracedHexWithoutU',
        'regex.lint.unicode.multibyteInClassWithoutU',
        'regex.lint.unicode.quantifiedMultibyteWithoutU',
    ];

    /**
     * What a continuation line of a formatted pattern gets on top of the
     * indentation of the line the literal starts on.
     */
    private const CONTINUATION_INDENT = '    ';

    private readonly PatternFormatter $formatter;

    /**
     * @param array<string, bool> $lintRules the lint rules regex.json turns on or off, by id
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly DocumentManager $documents,
        private readonly Regex $regex,
        private readonly array $lintRules = []
    ) {
        $this->formatter = new PatternFormatter(target: $regex->target());
    }

    /**
     * Handle textDocument/codeAction request.
     */
    public function handle(Message $message): void
    {
        $params = $message->params ?? [];
        /** @var array{uri?: string} $textDocument */
        $textDocument = $params['textDocument'] ?? [];
        $uri = isset($textDocument['uri']) && \is_string($textDocument['uri']) ? $textDocument['uri'] : null;
        /** @var Range|null $range */
        $range = isset($params['range']) && \is_array($params['range']) ? $params['range'] : null;
        /** @var array{diagnostics?: list<array{code?: int|string|null}>} $context */
        $context = isset($params['context']) && \is_array($params['context']) ? $params['context'] : [];

        if (null === $message->id || null === $uri || null === $range) {
            $this->transport->success($message->id ?? 0, []);

            return;
        }

        $codeActions = [];

        // Find regex occurrences that overlap with the requested range
        $occurrences = $this->findOccurrencesInRange($uri, $range);

        foreach ($occurrences as $occurrence) {
            // Add Unicode fix actions
            $codeActions = array_merge(
                $codeActions,
                $this->getUnicodeFixActions($occurrence, $uri, $context),
            );

            // Add optimization actions
            $codeActions = array_merge(
                $codeActions,
                $this->getOptimizationActions($occurrence, $uri),
            );

            // Add layout actions
            $codeActions = array_merge(
                $codeActions,
                $this->getLayoutActions($occurrence, $uri),
            );
        }

        $this->transport->success($message->id, $codeActions);
    }

    /**
     * Find occurrences that overlap with the given range.
     *
     * @param Range $range
     *
     * @return array<RegexOccurrence>
     */
    private function findOccurrencesInRange(string $uri, array $range): array
    {
        $occurrences = [];

        foreach ($this->documents->getOccurrences($uri) as $occurrence) {
            if ($this->rangesOverlap($occurrence->start, $occurrence->end, $range['start'], $range['end'])) {
                $occurrences[] = $occurrence;
            }
        }

        return $occurrences;
    }

    /**
     * Check if two ranges overlap.
     *
     * @param Position $start1
     * @param Position $end1
     * @param Position $start2
     * @param Position $end2
     */
    private function rangesOverlap(array $start1, array $end1, array $start2, array $end2): bool
    {
        // Check if range1 ends before range2 starts
        if ($end1['line'] < $start2['line']
            || ($end1['line'] === $start2['line'] && $end1['character'] < $start2['character'])) {
            return false;
        }

        // Check if range2 ends before range1 starts
        if ($end2['line'] < $start1['line']
            || ($end2['line'] === $start1['line'] && $end2['character'] < $start1['character'])) {
            return false;
        }

        return true;
    }

    /**
     * Get code actions for fixing Unicode lint issues.
     *
     * @param array{diagnostics?: list<array{code?: int|string|null}>} $context
     *
     * @return list<CodeAction>
     */
    private function getUnicodeFixActions(RegexOccurrence $occurrence, string $uri, array $context): array
    {
        $actions = [];

        // Check if there are Unicode-related diagnostics
        $diagnostics = isset($context['diagnostics']) && \is_array($context['diagnostics']) ? $context['diagnostics'] : [];
        $hasUnicodeIssue = false;

        foreach ($diagnostics as $diagnostic) {
            $code = $diagnostic['code'] ?? '';
            if (\in_array($code, self::UNICODE_LINT_IDS, true)) {
                $hasUnicodeIssue = true;

                break;
            }
        }

        if (!$hasUnicodeIssue) {
            // Also check by running the linter
            try {
                $ast = $this->regex->parse($occurrence->pattern);
                $linter = new PatternLinter($this->lintRules);
                $ast->accept($linter);

                foreach ($linter->getIssues() as $issue) {
                    if (\in_array($issue->id, self::UNICODE_LINT_IDS, true)) {
                        $hasUnicodeIssue = true;

                        break;
                    }
                }
            } catch (LexerException|LimitException|ParserException) {
                return [];
            }
        }

        if ($hasUnicodeIssue && !str_contains($occurrence->pattern, '/u')) {
            // Create action to add /u flag
            $newPattern = $this->addUnicodeFlag($occurrence->pattern);

            if (null !== $newPattern) {
                $actions[] = [
                    'title' => 'Add /u flag for Unicode support',
                    'kind' => 'quickfix',
                    'diagnostics' => array_values(array_filter($diagnostics, static fn ($d) => \in_array($d['code'] ?? '', self::UNICODE_LINT_IDS, true))),
                    'isPreferred' => true,
                    'edit' => [
                        'changes' => [
                            $uri => [
                                [
                                    'range' => [
                                        'start' => $occurrence->start,
                                        'end' => $occurrence->end,
                                    ],
                                    'newText' => $this->asPhpString($newPattern),
                                ],
                            ],
                        ],
                    ],
                ];
            }
        }

        return $actions;
    }

    /**
     * Get code actions for applying optimizations.
     *
     * @return list<CodeAction>
     */
    private function getOptimizationActions(RegexOccurrence $occurrence, string $uri): array
    {
        $actions = [];

        try {
            // Only a rewrite the automata prove is offered, as the lint
            // checks the rewrites it suggests: the defaults prove each one.
            $result = $this->regex->optimize($occurrence->pattern);

            if ($result->isChanged()) {
                $actions[] = [
                    'title' => 'Apply regex optimization',
                    'kind' => 'refactor.rewrite',
                    'edit' => [
                        'changes' => [
                            $uri => [
                                [
                                    'range' => [
                                        'start' => $occurrence->start,
                                        'end' => $occurrence->end,
                                    ],
                                    'newText' => $this->asPhpString($result->optimized),
                                ],
                            ],
                        ],
                    ],
                ];
            }
        } catch (LexerException|LimitException|ParserException) {
            // Pattern can't be parsed, skip optimization
        }

        return $actions;
    }

    /**
     * Get the code actions that lay the pattern out over lines or put it
     * back on one line.
     *
     * Each is offered only when it would change the literal's text: a
     * literal the format action wrote carries the extra indentation of its
     * continuation lines, so the formatter alone would call it changed.
     *
     * @return list<CodeAction>
     */
    private function getLayoutActions(RegexOccurrence $occurrence, string $uri): array
    {
        try {
            $ast = $this->regex->parse($occurrence->pattern);
        } catch (LexerException|LimitException|ParserException) {
            return [];
        }

        $actions = [];

        $formatted = $this->layout(fn (RegexNode $ast): FormatResult => $this->formatter->format($ast), $ast);
        if (null !== $formatted) {
            // Every line break of a formatted pattern is layout, so shifting
            // the continuation lines does not change what it matches. A blank
            // line stays blank.
            $indent = $this->lineIndentation($occurrence, $uri).self::CONTINUATION_INDENT;
            $lines = explode("\n", $formatted);
            foreach ($lines as $i => $line) {
                if ($i > 0 && '' !== $line) {
                    $lines[$i] = $indent.$line;
                }
            }
            $formatted = implode("\n", $lines);
            if ($formatted !== $occurrence->pattern) {
                $actions[] = $this->rewriteAction('Format pattern over lines', $occurrence, $uri, $formatted);
            }
        }

        $compacted = $this->layout(fn (RegexNode $ast): FormatResult => $this->formatter->compact($ast), $ast);
        if (null !== $compacted && $compacted !== $occurrence->pattern) {
            $actions[] = $this->rewriteAction('Compact pattern to one line', $occurrence, $uri, $compacted);
        }

        return $actions;
    }

    /**
     * The pattern the formatter writes, or null when it refuses to or
     * cannot write one that reads back as the same pattern.
     *
     * @param \Closure(RegexNode): FormatResult $layout
     */
    private function layout(\Closure $layout, RegexNode $ast): ?string
    {
        try {
            $result = $layout($ast);
        } catch (FormattingException) {
            return null;
        }

        return null === $result->refusal ? $result->pattern : null;
    }

    /**
     * The spaces and tabs the literal's first line starts with, as written.
     */
    private function lineIndentation(RegexOccurrence $occurrence, string $uri): string
    {
        $content = $this->documents->getContent($uri) ?? '';
        $before = substr($content, 0, $occurrence->byteOffset);
        // Behind a leading line break, the last one sits where the line starts.
        $lineStart = (int) strrpos("\n".$before, "\n");

        return substr($before, $lineStart, strspn($before, " \t", $lineStart));
    }

    /**
     * A refactor.rewrite action replacing the literal with the pattern.
     *
     * @return RewriteAction
     */
    private function rewriteAction(string $title, RegexOccurrence $occurrence, string $uri, string $pattern): array
    {
        return [
            'title' => $title,
            'kind' => 'refactor.rewrite',
            'edit' => [
                'changes' => [
                    $uri => [
                        [
                            'range' => [
                                'start' => $occurrence->start,
                                'end' => $occurrence->end,
                            ],
                            'newText' => $this->asPhpString($pattern),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The pattern as a PHP string literal, ready to replace the one in the
     * source.
     *
     * A pattern is free to hold a quote — "/it's/" is a fine regex — and
     * wrapping it in quotes without escaping produced source the editor
     * could not parse.
     */
    private function asPhpString(string $pattern): string
    {
        return var_export($pattern, true);
    }

    /**
     * Add /u flag to a regex pattern.
     */
    private function addUnicodeFlag(string $pattern): ?string
    {
        if (\strlen($pattern) < 2) {
            return null;
        }

        $delimiter = $pattern[0];
        $closingDelimiters = ['/' => '/', '#' => '#', '~' => '~', '@' => '@', '!' => '!', '%' => '%',
            '(' => ')', '[' => ']', '{' => '}', '<' => '>'];

        $closingDelimiter = $closingDelimiters[$delimiter] ?? $delimiter;

        // Find the last occurrence of the closing delimiter
        $lastDelimPos = strrpos($pattern, $closingDelimiter);
        if (false === $lastDelimPos || 0 === $lastDelimPos) {
            return null;
        }

        // Extract existing flags
        $flags = substr($pattern, $lastDelimPos + 1);

        // Already has /u
        if (str_contains($flags, 'u')) {
            return null;
        }

        // Add /u flag
        return substr($pattern, 0, $lastDelimPos + 1).'u'.$flags;
    }
}
