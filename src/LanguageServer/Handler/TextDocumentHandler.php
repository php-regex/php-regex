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
use PHPRegex\LanguageServer\Converter\PatternSpan;
use PHPRegex\LanguageServer\Converter\PositionConverter;
use PHPRegex\LanguageServer\Document\DocumentManager;
use PHPRegex\LanguageServer\Document\PatternDeclarations;
use PHPRegex\LanguageServer\Document\RegexOccurrence;
use PHPRegex\LanguageServer\Protocol\Message;
use PHPRegex\LanguageServer\Protocol\Transport;
use PHPRegex\Linter\Config\JudgedRange;
use PHPRegex\Linter\Config\TargetConstraint;
use PHPRegex\Linter\Extraction\IgnoreMarker;
use PHPRegex\Linter\Extraction\InlineIgnores;
use PHPRegex\Linter\Extraction\LiteralSpelling;
use PHPRegex\Linter\Extraction\TargetMarker;
use PHPRegex\Linter\Extraction\TargetMarkers;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\RangeFindings;
use PHPRegex\Linter\RangeWording;
use PHPRegex\Linter\RedosVerdict;
use PHPRegex\Linter\Rule\InvisibleCharacterRule;
use PHPRegex\Linter\Rule\RuleCatalog;
use PHPRegex\Linter\Rule\RuleId;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\LimitException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;

/**
 * Handles text document notifications and requests.
 *
 * A pattern is judged as `regex lint` judges it: at the lowest version of
 * the project's range, then at each later version of the range, a later
 * PHP that refuses it being an error, one that reads it otherwise a
 * warning. A guard on PHP_VERSION_ID and a "@regex-target" marker narrow
 * the versions judged.
 *
 * @phpstan-type Refusal array{string, ErrorCode, ?int, ?string}
 * @phpstan-type Analysis array{0: 'invalid', 1: ValidationResult}|array{0: 'read', 1: string, 2: list<RuleViolation>, 3: ?RedosAnalysis, 4: ?Refusal, 5: ?string}|array{0: 'limit', 1: string, 2: ErrorCode}|array{0: 'unread', 1: string, 2: ErrorCode, 3: ?int, 4: ?string}
 *
 * @phpstan-import-type Diagnostic from DiagnosticConverter
 *
 * @internal
 */
final class TextDocumentHandler
{
    /**
     * FileChangeType.Deleted.
     */
    private const FILE_DELETED = 3;

    /**
     * How many patterns' findings the server keeps, the latest read first.
     */
    private const MAX_KEPT_ANALYSES = 4096;

    private readonly DiagnosticConverter $diagnosticConverter;

    /**
     * The Regex of each lowest version a "@regex-target" marker keeps.
     *
     * @var array<string, Regex>
     */
    private array $narrowed = [];

    /**
     * What each pattern was found to hold, by the versions that judged it
     * and the pattern: the same for every place the pattern stands, so an
     * edit analyses only the patterns whose text it changed. The lint rules
     * and the ReDoS settings are the server's, fixed.
     *
     * @var array<string, Analysis>
     */
    private array $analyses = [];

    /**
     * The parsers of the versions of the range after the floor, sharing
     * the floor's cache: none when the project is judged for one version.
     *
     * @var list<RegexParser>
     */
    private readonly array $range;

    /**
     * @param array<string, bool> $lintRules              the lint rules regex.json turns on or off, by id
     * @param RedosMode           $redosMode              the ReDoS analysis to run, none by default
     * @param RedosSeverity       $redosThreshold         the lowest ReDoS severity reported
     * @param bool                $reportUnmatchedIgnores whether a marker that silences nothing is a warning
     * @param JudgedRange|null    $judged                 the versions the project is judged for, for a
     *                                                    "@regex-target" marker to narrow
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly DocumentManager $documents,
        private readonly Regex $regex,
        private readonly array $lintRules = [],
        private readonly RedosMode $redosMode = RedosMode::Off,
        private readonly RedosSeverity $redosThreshold = RedosSeverity::High,
        private readonly bool $reportUnmatchedIgnores = true,
        private readonly ?JudgedRange $judged = null,
    ) {
        $this->diagnosticConverter = new DiagnosticConverter();
        $this->range = null === $judged ? [] : array_map($judged->parserFor(...), \array_slice($judged->points(), 1));
    }

    /**
     * Handle textDocument/didOpen notification.
     */
    public function didOpen(Message $message): void
    {
        $params = $message->params ?? [];
        /** @var array{uri?: string, text?: string, languageId?: string, version?: int} $textDocument */
        $textDocument = $params['textDocument'] ?? [];
        $uri = isset($textDocument['uri']) && \is_string($textDocument['uri']) ? $textDocument['uri'] : null;
        $text = isset($textDocument['text']) && \is_string($textDocument['text']) ? $textDocument['text'] : null;
        $version = isset($textDocument['version']) && \is_int($textDocument['version']) ? $textDocument['version'] : null;

        if (null === $uri || null === $text) {
            return;
        }

        $this->publishFloorFirst($uri, ...$this->documents->open($uri, $text, $version));
    }

    /**
     * Handle textDocument/didChange notification.
     */
    public function didChange(Message $message): void
    {
        $params = $message->params ?? [];
        /** @var array{uri?: string, version?: int} $textDocument */
        $textDocument = $params['textDocument'] ?? [];
        $uri = isset($textDocument['uri']) && \is_string($textDocument['uri']) ? $textDocument['uri'] : null;
        $version = isset($textDocument['version']) && \is_int($textDocument['version']) ? $textDocument['version'] : null;
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

        $this->publishDiagnostics($uri, ...$this->documents->update($uri, $text, $version));
    }

    /**
     * Handle textDocument/didClose notification.
     */
    public function didClose(Message $message): void
    {
        $params = $message->params ?? [];
        /** @var array{uri?: string} $textDocument */
        $textDocument = $params['textDocument'] ?? [];
        $uri = isset($textDocument['uri']) && \is_string($textDocument['uri']) ? $textDocument['uri'] : null;

        if (null === $uri) {
            return;
        }

        $affected = $this->documents->close($uri);

        // Clear diagnostics
        $this->transport->notification('textDocument/publishDiagnostics', [
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
        /** @var array{uri?: string} $textDocument */
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

            // A library's file is no file of the project: the event for it
            // is not read. Composer's manifest, watched on its own, reads
            // the libraries again — every file of theirs with it.
            if (!$this->documents->readsWatchedChange($uri)) {
                continue;
            }

            $declarations = $this->documents->declarations();
            $changed = (self::FILE_DELETED === ($change['type'] ?? null) ? $declarations->forgetFile($path) : $declarations->readFile($path)) || $changed;
        }

        if ($changed) {
            $this->publishFloorFirst(...$this->documents->refresh());
        }
    }

    /**
     * Handle textDocument/hover request.
     */
    public function hover(Message $message): void
    {
        $params = $message->params ?? [];
        /** @var array{uri?: string} $textDocument */
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
            $this->transport->success($message->id, null);

            return;
        }

        try {
            $ast = $this->regex->parse($occurrence->pattern);
            $explainer = new TextExplainer($this->regex->target());
            $explanation = $ast->accept($explainer);

            $markdown = "**Regex Pattern**\n\n```\n{$occurrence->pattern}\n```\n\n".(null === $occurrence->writtenElsewhere ? '' : "{$occurrence->writtenElsewhere}\n\n")."**Explanation**\n\n{$explanation}";

            $this->transport->success($message->id, [
                'contents' => [
                    'kind' => 'markdown',
                    'value' => $markdown,
                ],
                'range' => [
                    'start' => $occurrence->start,
                    'end' => $occurrence->end,
                ],
            ]);
        } catch (LimitException $e) {
            $this->transport->success($message->id, [
                'contents' => [
                    'kind' => 'markdown',
                    'value' => "**Not analyzed**\n\n{$e->getMessage()}",
                ],
            ]);
        } catch (LexerException|ParserException $e) {
            $this->transport->success($message->id, [
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

    /**
     * Publish what the lowest version judged says of each document, then
     * what every version says: the later versions of a range take most of
     * the time of a document read whole, and the editor need not wait for
     * them to show the floor's diagnostics. One version judged: one push.
     */
    private function publishFloorFirst(string ...$uris): void
    {
        if ([] !== $this->range) {
            foreach ($uris as $uri) {
                $this->publishDocument($uri, floorOnly: true);
            }
        }

        $this->publishDiagnostics(...$uris);
    }

    /**
     * @param bool $floorOnly whether to judge the lowest version alone: a
     *                        first push, which says nothing of the markers
     *                        silencing nothing, since a later version may
     *                        give what they silence
     */
    private function publishDocument(string $uri, bool $floorOnly = false): void
    {
        $diagnostics = [];
        $content = $this->documents->getContent($uri) ?? '';
        $positions = new PositionConverter($content);
        // A library's file is no file of the project: `regex lint` leaves
        // vendor/ out, and so does the editor.
        $library = $this->documents->isLibrary($uri);
        $occurrences = $library ? [] : $this->documents->getOccurrences($uri);
        $markers = $library ? [] : InlineIgnores::markers($content);
        $targets = $library ? [] : TargetMarkers::read($content);

        // Keep the source indices for unmatched-marker reporting, while
        // looking up only the markers covering each occurrence's line.
        /** @var array<int, array<int, IgnoreMarker>> $markersByLine */
        $markersByLine = [];
        foreach ($markers as $index => $marker) {
            $markersByLine[$marker->coveredLine][$index] = $marker;
        }

        // "@regex-ignore" silences the issues of its line, as in `regex
        // lint`: every one, or those it names.
        $used = [];
        foreach ($occurrences as $occurrence) {
            $covering = $markersByLine[$occurrence->line] ?? [];
            if ($occurrence->ignored && [] === $covering) {
                continue;
            }

            // A guard on PHP_VERSION_ID and "@regex-target" judge the pattern
            // for the versions they keep, from the lowest; for none, not at
            // all.
            $marker = TargetMarker::constraintAt($targets, $occurrence->line);
            $constraint = null === $occurrence->guard ? $marker : ($marker?->intersect($occurrence->guard) ?? $occurrence->guard);
            $regex = $this->regexFor($constraint);
            foreach (null === $regex ? [] : $this->occurrenceDiagnostics($occurrence, $content, $positions, $regex, $floorOnly ? [] : $this->rangeFor($constraint)) as $diagnostic) {
                $silenced = false;
                foreach ($covering as $index => $marker) {
                    if ($marker->silences($diagnostic['code'])) {
                        $used[$index][[] === $marker->identifiers ? '' : $diagnostic['code']] = true;
                        $silenced = true;
                    }
                }

                if (!$silenced) {
                    $diagnostics[] = $diagnostic;
                }
            }
        }

        // A "@regex-target" marker that cannot be read, or keeps no version
        // the project is judged for: an error on the marker.
        foreach ($targets as $marker) {
            $message = null === $marker->constraint
                ? $marker->error
                : (null !== $this->judged && [] === $this->judged->narrow($marker->constraint) ? \sprintf('The %s marker "%s" keeps no version the project is judged for (PHP %s): the patterns it covers are not judged.', $marker->marker, $marker->text, $this->judged->describe()) : null);
            if (null !== $message) {
                $range = ['start' => $positions->offsetToPosition($marker->offset), 'end' => $positions->offsetToPosition($marker->offset + \strlen($marker->marker))];
                $diagnostics[] = $this->diagnosticConverter->fromTargetMarker(null === $marker->constraint ? 'regex.target.invalid' : 'regex.target.empty', $message, $range);
            }
        }

        // A marker that silences nothing: a warning on the marker.
        foreach ($this->reportUnmatchedIgnores && !$floorOnly ? $markers : [] as $index => $marker) {
            $range = ['start' => $positions->offsetToPosition($marker->offset), 'end' => $positions->offsetToPosition($marker->offset + \strlen($marker->marker))];
            foreach ($marker->unmatched($used[$index] ?? [], $this->leavesOff(...)) as $message) {
                $diagnostics[] = $this->diagnosticConverter->fromUnmatchedIgnore($message, $range);
            }
        }

        $payload = [
            'uri' => $uri,
            'diagnostics' => $diagnostics,
        ];

        // The push names the state it diagnoses, as the client numbered
        // it: the editor drops one that names a state the document left.
        $version = $this->documents->getVersion($uri);
        if (null !== $version) {
            $payload['version'] = $version;
        }

        $this->transport->notification('textDocument/publishDiagnostics', $payload);
    }

    /**
     * What `regex lint` reports for one pattern, before any marker.
     *
     * @param list<RegexParser> $range the parsers of the later versions judged
     *
     * @return list<Diagnostic>
     */
    private function occurrenceDiagnostics(RegexOccurrence $occurrence, string $content, PositionConverter $positions, Regex $regex, array $range): array
    {
        $diagnostics = $this->patternDiagnostics($occurrence, $content, $positions, $regex, $range);
        if (null === $occurrence->writtenElsewhere) {
            return $diagnostics;
        }

        // A pattern another file writes is reported at the argument naming
        // it: each message says which constant, and where it is written.
        return array_map(static fn (array $diagnostic): array => ['message' => $occurrence->writtenElsewhere.': '.$diagnostic['message']] + $diagnostic, $diagnostics);
    }

    /**
     * What `regex lint` reports for one pattern, before any marker, each
     * diagnostic placed through the span of its pattern.
     *
     * @param list<RegexParser> $range the parsers of the later versions judged
     *
     * @return list<Diagnostic>
     */
    private function patternDiagnostics(RegexOccurrence $occurrence, string $content, PositionConverter $positions, Regex $regex, array $range): array
    {
        $diagnostics = [];

        // Offsets count in bytes of the pattern body; the span places
        // them through the literal's escapes, in UTF-16 columns.
        $span = PatternSpan::of($occurrence, $positions);
        $analysis = $this->analysis($occurrence->pattern, $regex, $range);

        if ('invalid' === $analysis[0]) {
            // A pattern PCRE refuses gets that error, and no lint issue.
            $validation = $analysis[1];

            return [$this->diagnosticConverter->fromValidationError(
                $validation->error ?? 'Invalid regex.',
                $validation->errorCode ?? ErrorCode::PcreRuntime,
                $span,
                $validation->offset,
                $validation->hint,
            )];
        }

        if ('limit' === $analysis[0]) {
            return [$this->diagnosticConverter->fromLimit($analysis[1], $analysis[2], $span)];
        }

        if ('unread' === $analysis[0]) {
            // The fix comes with the validation's verdict on the same error.
            return [$this->diagnosticConverter->fromParseError($analysis[1], $analysis[2], $span, $analysis[3], $analysis[4])];
        }

        [, $source, $issues, $redos, $refusal, $change] = $analysis;

        // A later PHP of the range that refuses the pattern, or reads it
        // otherwise, as `regex lint` reports it.
        if (null !== $refusal) {
            $diagnostics[] = $this->diagnosticConverter->fromValidationError($refusal[0], $refusal[1], $span, $refusal[2], $refusal[3]);
        }
        if (null !== $change) {
            $diagnostics[] = $this->diagnosticConverter->fromMeaningChange($change, $span);
        }

        $spelling = null;
        foreach ($issues as $issue) {
            // The heuristic rules guess what the analysis just proved:
            // once it shows the pattern linear, their warnings go.
            if (true === $redos?->isProvenSafe() && isset(RedosVerdict::HEURISTIC_ISSUE_IDS[$issue->id])) {
                continue;
            }

            // A raw character the literal spells with an escape, as
            // a double-quoted "\t", is no raw character in the source; the
            // literal of another file is that file's to report.
            if (InvisibleCharacterRule::ID === $issue->id) {
                if (null !== $occurrence->writtenElsewhere) {
                    continue;
                }

                $spelling ??= LiteralSpelling::fromSource($content, $occurrence->byteOffset);
                if (!$spelling->containsRaw(InvisibleCharacterRule::reportedCharacter($source, (int) $issue->offset))) {
                    continue;
                }
            }

            $diagnostics[] = $this->diagnosticConverter->convert($issue, $span);
        }

        if (null !== $redos) {
            array_push($diagnostics, ...$this->redosDiagnostics($redos, $span));
        }

        return $diagnostics;
    }

    /**
     * What the pattern holds for the Regex of the floor and the later
     * versions judged, wherever it stands: worked out once, then kept.
     *
     * @param list<RegexParser> $range the parsers of the later versions judged
     *
     * @return Analysis
     */
    private function analysis(string $pattern, Regex $regex, array $range): array
    {
        $key = spl_object_id($regex);
        foreach ($range as $parser) {
            $key .= ','.$parser->target()->key();
        }
        $key .= "\0".$pattern;
        if (isset($this->analyses[$key])) {
            $analysis = $this->analyses[$key];
            // Read again, it is the latest.
            unset($this->analyses[$key]);

            return $this->analyses[$key] = $analysis;
        }

        try {
            $ast = $regex->parse($pattern);

            $validation = $regex->validate($pattern);
            if (!$validation->isValid && null !== $validation->errorCode) {
                $analysis = ['invalid', $validation];
            } else {
                $linter = new PatternLinter($this->lintRules);
                $ast->accept($linter);

                $redos = RedosMode::Off === $this->redosMode ? null : $regex->redos($pattern, $this->redosThreshold, $this->redosMode);
                [$refusal, $change] = [] === $range ? [null, null] : $this->rangeFindings($pattern, $ast, $regex->parser(), $range);
                $analysis = ['read', $ast->source ?? '', $linter->getIssues(), $redos, $refusal, $change];
            }
        } catch (LimitException $e) {
            $analysis = ['limit', $e->getMessage(), $e->errorCode];
        } catch (LexerException|ParserException $e) {
            $analysis = ['unread', $e->getMessage(), $e->errorCode, $e->position, $regex->validate($pattern)->hint];
        }

        $this->analyses[$key] = $analysis;
        if (\count($this->analyses) > self::MAX_KEPT_ANALYSES) {
            unset($this->analyses[array_key_first($this->analyses)]);
        }

        return $analysis;
    }

    /**
     * What the later versions of the range find in a pattern the floor
     * accepts, worded as `regex lint` words it: the refusal of the lowest
     * that refuses it, as its message, error code, offset and hint; else
     * the first that reads it into another tree, or matches the same tree
     * otherwise, unless the rules map turns that check off.
     *
     * @param list<RegexParser> $range the parsers of the later versions judged, not empty
     *
     * @return array{?Refusal, ?string}
     */
    private function rangeFindings(string $pattern, RegexNode $ast, RegexParser $floor, array $range): array
    {
        $findings = RangeFindings::of($floor, $range, $pattern, $ast, RuleCatalog::enables($this->lintRules, RuleCatalog::MEANING_CHANGES));

        $refused = $findings->refusedBy;
        if (null !== $refused) {
            return [[
                \sprintf('On %s: %s', $findings->refusedOn, $refused->validation->error ?? 'Invalid regex.'),
                $refused->validation->errorCode ?? ErrorCode::PcreRuntime,
                $refused->validation->offset,
                $refused->validation->hint,
            ], null];
        }

        if (null !== $findings->readsOtherwise) {
            return [null, RangeWording::readsOtherwise($findings->readsOtherwise)];
        }

        $silentChange = $findings->silentChange;

        return [null, null === $silentChange ? null : RangeWording::silentChange($silentChange->target, $silentChange->message)];
    }

    /**
     * Whether the editor leaves off the check an issue identifier names: a
     * lint rule the rules map turns off, the ReDoS analysis when off, the
     * change of meaning across the range when its rule is off or the range
     * is one version, and what it never reports, the optimizations and the
     * complexity score.
     */
    private function leavesOff(string $identifier): bool
    {
        return match ($identifier) {
            RedosVerdict::ISSUE_ID => RedosMode::Off === $this->redosMode,
            RedosVerdict::SEARCH_ISSUE_ID => RedosMode::Off === $this->redosMode
                || !RuleCatalog::enables($this->lintRules, RedosVerdict::SEARCH_ISSUE_ID),
            RuleCatalog::MEANING_CHANGES => [] === $this->range || !RuleCatalog::enables($this->lintRules, RuleCatalog::MEANING_CHANGES),
            'regex.optimization', 'regex.lint.complexity' => true,
            default => RuleId::isLintRule($identifier) && !RuleCatalog::enables($this->lintRules, $identifier),
        };
    }

    /**
     * The Regex judging a pattern a guard or a "@regex-target" constraint
     * covers: that of the lowest version it keeps, the server's when it
     * keeps it or when no constraint covers the pattern; null when it keeps
     * no version.
     */
    private function regexFor(?TargetConstraint $constraint): ?Regex
    {
        if (null === $constraint || null === $this->judged) {
            return $this->regex;
        }

        $lowest = $this->judged->narrow($constraint)[0] ?? null;
        if (null === $lowest) {
            return null;
        }

        // The server's options, its locale included, for the lowest version.
        return $this->narrowed[$lowest->key()] ??= $this->regex->withTarget($lowest);
    }

    /**
     * The parsers of the versions after the lowest that judge a pattern a
     * guard or a "@regex-target" constraint covers: those it keeps, the
     * server's range when no constraint covers the pattern. They share the
     * cache of the server's parser.
     *
     * @return list<RegexParser>
     */
    private function rangeFor(?TargetConstraint $constraint): array
    {
        if (null === $constraint || null === $this->judged) {
            return $this->range;
        }

        return array_map($this->judged->parserFor(...), \array_slice($this->judged->narrow($constraint), 1));
    }

    /**
     * The verdict and the search cost `regex lint` reports for the analysis,
     * under the same ids, severities and words.
     *
     * @return list<Diagnostic>
     */
    private function redosDiagnostics(RedosAnalysis $redos, PatternSpan $span): array
    {
        $diagnostics = [];

        if (RedosVerdict::reported($redos, $this->redosThreshold, $this->redosMode)) {
            $hotspot = $redos->primaryHotspot();
            $diagnostics[] = $this->diagnosticConverter->fromRedos(
                RedosVerdict::ISSUE_ID,
                RedosVerdict::message($redos),
                RedosVerdict::evidence($redos),
                RedosVerdict::isError($redos),
                $span,
                $hotspot?->start,
                $hotspot?->end,
            );
        }

        // One attempt proven linear, the search retrying it is not.
        $searchCost = $redos->searchCost;
        if (null !== $searchCost
            && RuleCatalog::enables($this->lintRules, RedosVerdict::SEARCH_ISSUE_ID)
            && RedosVerdict::searchSeverity()->rank() >= $this->redosThreshold->rank()) {
            $diagnostics[] = $this->diagnosticConverter->fromRedos(
                RedosVerdict::SEARCH_ISSUE_ID,
                RedosVerdict::searchMessage($searchCost),
                RedosVerdict::searchEvidence($searchCost, $redos->pcreVersion),
                false,
                $span,
            );
        }

        return $diagnostics;
    }
}
