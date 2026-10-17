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

namespace PHPRegex\Linter;

use PHPRegex\Linter\Config\TargetConstraint;
use PHPRegex\Linter\Extraction\IgnoreMarker;
use PHPRegex\Linter\Extraction\TargetMarker;

/**
 * Represents a regex pattern occurrence found in source code. One with an
 * $unread reason stands for a file the extractor could not read: it holds
 * no pattern, and the lint reports it as regex.lint.source.unreadable. One
 * with a $parserFallback reason stands for a file the PHP parser could not
 * read and the tokenizer read instead: it holds no pattern either, and the
 * lint only counts it. One with $ignores stands for a PHP file the run
 * read: it holds no pattern, and carries the inline markers of the file, so
 * that the lint reports those that silence nothing and knows which files a
 * baseline entry may name.
 *
 * $guard holds the versions the code holding the pattern runs on, as a
 * guard on PHP_VERSION_ID or a "@regex-target" marker says them: the
 * pattern is judged for those of the project range alone. $targets, on a
 * file read, holds its "@regex-target" markers, for the lint to report the
 * malformed ones and those that keep no version.
 *
 * $isIgnored is set on an occurrence whose line a marker naming no issue
 * silences: an analysis given it alone leaves it out. The lint, given the
 * markers of the file, analyses it and drops its issues itself.
 *
 * @internal
 */
final class PatternOccurrence
{
    public function __construct(
        public readonly string $pattern,
        public readonly string $file,
        public readonly int $line,
        public readonly string $source,
        public readonly ?string $displayPattern = null,
        public readonly ?string $location = null,
        public readonly bool $isIgnored = false,
        public readonly ?int $column = null,
        public readonly ?int $fileOffset = null,
        public readonly ?string $unread = null,
        public readonly ?string $parserFallback = null,
        /**
         * @var list<IgnoreMarker>|null
         */
        public readonly ?array $ignores = null,
        public readonly ?TargetConstraint $guard = null,
        /**
         * @var list<TargetMarker>
         */
        public readonly array $targets = [],
    ) {}

    /**
     * A PHP file the run read, and its inline markers.
     *
     * @param list<IgnoreMarker> $ignores
     * @param list<TargetMarker> $targets
     */
    public static function scanned(string $file, array $ignores, array $targets = []): self
    {
        return new self('', $file, 1, 'php', ignores: $ignores, targets: $targets);
    }

    /**
     * The same occurrence, its line silenced whole by a marker naming no
     * issue.
     */
    public function ignoredWhole(): self
    {
        return new self($this->pattern, $this->file, $this->line, $this->source, $this->displayPattern, $this->location, true, $this->column, $this->fileOffset, $this->unread, $this->parserFallback, $this->ignores, $this->guard, $this->targets);
    }

    /**
     * The same occurrence, judged for the versions a guard or a marker
     * keeps.
     */
    public function guardedBy(?TargetConstraint $guard): self
    {
        return null === $guard ? $this : new self($this->pattern, $this->file, $this->line, $this->source, $this->displayPattern, $this->location, $this->isIgnored, $this->column, $this->fileOffset, $this->unread, $this->parserFallback, $this->ignores, $this->guard?->intersect($guard) ?? $guard, $this->targets);
    }

    /**
     * The same occurrence, analysed whatever a marker says: the lint drops
     * its issues itself.
     */
    public function analysed(): self
    {
        return new self($this->pattern, $this->file, $this->line, $this->source, $this->displayPattern, $this->location, false, $this->column, $this->fileOffset, $this->unread, $this->parserFallback, $this->ignores, $this->guard, $this->targets);
    }

    /**
     * A file the extractor could not read, and why.
     */
    public static function unread(string $file, string $reason): self
    {
        return new self('', $file, 1, 'php', unread: $reason);
    }

    /**
     * A file the tokenizer read because the PHP parser could not, and why.
     */
    public static function parserFallback(string $file, string $reason): self
    {
        return new self('', $file, 1, 'php', parserFallback: $reason);
    }
}
