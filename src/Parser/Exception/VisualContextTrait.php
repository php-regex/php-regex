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

namespace PhpRegex\Parser\Exception;

/**
 * Provides common visual context helpers for parser-related exceptions.
 */
trait VisualContextTrait
{
    private const MAX_CONTEXT_WIDTH = 80;

    private ?string $pattern = null;

    private string $visualSnippet = '';

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function getPattern(): ?string
    {
        return $this->pattern;
    }

    public function getVisualSnippet(): string
    {
        return $this->visualSnippet;
    }

    private function initializeContext(?int $position, ?string $pattern): void
    {
        $this->pattern = $pattern;
        $this->visualSnippet = $this->buildVisualSnippet($position, $pattern);
    }

    private function buildVisualSnippet(?int $position, ?string $pattern): string
    {
        if (null === $pattern || null === $position || $position < 0) {
            return '';
        }

        $length = \strlen($pattern);
        $caretIndex = $position > $length ? $length : $position;

        $lineStart = strrpos($pattern, "\n", $caretIndex - $length);
        $lineStart = false === $lineStart ? 0 : $lineStart + 1;
        $lineEnd = strpos($pattern, "\n", $caretIndex);
        $lineEnd = false === $lineEnd ? $length : $lineEnd;

        $lineNumber = substr_count($pattern, "\n", 0, $lineStart) + 1;

        $displayStart = $lineStart;
        $displayEnd = $lineEnd;

        if (($displayEnd - $displayStart) > self::MAX_CONTEXT_WIDTH) {
            $half = intdiv(self::MAX_CONTEXT_WIDTH, 2);
            $displayStart = max($lineStart, $caretIndex - $half);
            $displayEnd = min($lineEnd, $displayStart + self::MAX_CONTEXT_WIDTH);

            if (($displayEnd - $displayStart) > self::MAX_CONTEXT_WIDTH) {
                $displayStart = $displayEnd - self::MAX_CONTEXT_WIDTH;
            }
        }

        $prefixEllipsis = $displayStart > $lineStart ? '...' : '';
        $suffixEllipsis = $displayEnd < $lineEnd ? '...' : '';

        $excerpt = $prefixEllipsis
            .substr($pattern, $displayStart, $displayEnd - $displayStart)
            .$suffixEllipsis;

        $caretOffset = ('' === $prefixEllipsis ? 0 : 3) + ($caretIndex - $displayStart);
        if ($caretOffset < 0) {
            $caretOffset = 0;
        }

        $lineLabel = 'Line '.$lineNumber.': ';

        return $lineLabel.$excerpt."\n"
            .str_repeat(' ', \strlen($lineLabel) + $caretOffset).'^';
    }
}
