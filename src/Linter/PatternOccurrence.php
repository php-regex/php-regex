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

/**
 * Represents a regex pattern occurrence found in source code. One with an
 * $unread reason stands for a file the extractor could not read: it holds
 * no pattern, and the lint reports it as regex.lint.source.unreadable. One
 * with a $parserFallback reason stands for a file the PHP parser could not
 * read and the tokenizer read instead: it holds no pattern either, and the
 * lint only counts it.
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
    ) {}

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
