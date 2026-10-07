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

namespace PHPRegex\Symfony\Routing;

/**
 * Normalizes Symfony route requirements into full regex patterns.
 *
 * The route compiler strips a requirement's leading ^ or \A and its trailing
 * $ or \z, then puts it in a group of its own: a top-level alternation is
 * grouped here too, so that every alternative stays anchored.
 *
 * @internal
 */
final readonly class RouteRequirementNormalizer
{
    private const PATTERN_DELIMITERS = ['/', '#', '~', '%'];

    private const BODY_DELIMITERS = ['#', '~', '%', '!', '@', ';', '+', '=', ',', ':', '&', '"', "'", '`'];

    public function normalize(string $pattern): string
    {
        $firstChar = $pattern[0] ?? '';

        if (\in_array($firstChar, self::PATTERN_DELIMITERS, true)) {
            return $pattern;
        }

        [$body, $end] = $this->stripAnchors($pattern);

        if ($this->hasTopLevelAlternation($body)) {
            $body = '(?:'.$body.')';
        }

        // A delimiter the requirement does not hold leaves it as written: an
        // escape would double \#, break (?#...) and make a comment under an
        // inline (?x) a literal.
        foreach (self::BODY_DELIMITERS as $delimiter) {
            if (!str_contains($body, $delimiter)) {
                return $delimiter.'^'.$body.$end.$delimiter;
            }
        }

        return '#^'.$this->escapeHashes($body).$end.'#';
    }

    private function escapeHashes(string $body): string
    {
        $escaped = '';
        $length = \strlen($body);

        for ($i = 0; $i < $length; $i++) {
            if ('#' === $body[$i] && !$this->isEscaped($body, $i)) {
                $escaped .= '\\';
            }
            $escaped .= $body[$i];
        }

        return $escaped;
    }

    /**
     * The requirement without its leading and trailing anchors, and the end
     * anchor to close it with: the compiler ends with $ under D, so a \z it
     * strips stays a strict end here.
     *
     * @return array{string, string}
     */
    private function stripAnchors(string $pattern): array
    {
        if (str_starts_with($pattern, '^')) {
            $pattern = substr($pattern, 1);
        } elseif (str_starts_with($pattern, '\A')) {
            $pattern = substr($pattern, 2);
        }

        // An escaped $ is a literal, not the anchor.
        if (str_ends_with($pattern, '$') && !$this->isEscaped($pattern, \strlen($pattern) - 1)) {
            return [substr($pattern, 0, -1), '$'];
        }

        if (str_ends_with($pattern, '\z') && !$this->isEscaped($pattern, \strlen($pattern) - 2)) {
            return [substr($pattern, 0, -2), '\z'];
        }

        return [$pattern, '$'];
    }

    private function isEscaped(string $pattern, int $offset): bool
    {
        $backslashes = 0;
        while ($offset - $backslashes > 0 && '\\' === $pattern[$offset - $backslashes - 1]) {
            $backslashes++;
        }

        return 1 === $backslashes % 2;
    }

    private function hasTopLevelAlternation(string $body): bool
    {
        $depth = 0;
        $extended = false;
        $length = \strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ('\\' === $char) {
                if ('Q' === ($body[$i + 1] ?? '')) {
                    $end = strpos($body, '\E', $i + 2);
                    if (false === $end) {
                        return false;
                    }
                    $i = $end + 1;

                    continue;
                }
                $i++;

                continue;
            }

            if ($extended && '#' === $char) {
                // Under x a comment runs to the newline, its parentheses are text.
                $end = strpos($body, "\n", $i);
                if (false === $end) {
                    return false;
                }
                $i = $end;
            } elseif ('[' === $char) {
                $i = $this->classEnd($body, $i);
            } elseif (null !== ($options = $this->optionSetting($body, $i))) {
                // The x an option setting turns on or off holds to the end
                // of the requirement: its group's end is not tracked.
                $extended = $this->extendedAfter($options[0], $extended);
                $i = $options[1];
                if (':' === $body[$i]) {
                    $depth++;
                }
            } elseif (str_starts_with(substr($body, $i, 3), '(?#')) {
                // A comment runs to the first ), its parentheses are text.
                $end = strpos($body, ')', $i + 3);
                if (false === $end) {
                    return false;
                }
                $i = $end;
            } elseif ('(' === $char) {
                $depth++;
            } elseif (')' === $char) {
                $depth--;
            } elseif ('|' === $char && 0 === $depth) {
                return true;
            }
        }

        return false;
    }

    /**
     * The letters of an option setting opened at $start, "(?x)" or "(?-x:",
     * and the offset of the ) or : that ends them; null for any other text.
     *
     * @return array{string, int}|null
     */
    private function optionSetting(string $body, int $start): ?array
    {
        if ('(?' !== substr($body, $start, 2)) {
            return null;
        }

        $length = \strlen($body);
        for ($i = $start + 2; $i < $length; $i++) {
            if (')' === $body[$i] || ':' === $body[$i]) {
                return [substr($body, $start + 2, $i - $start - 2), $i];
            }
            $lower = \ord($body[$i]) | 0x20;
            if (($lower < 0x61 || $lower > 0x7A) && '-' !== $body[$i] && '^' !== $body[$i]) {
                return null;
            }
        }

        return null;
    }

    /**
     * Whether x is on once the option letters apply: ^ resets it, a letter
     * after - turns it off.
     */
    private function extendedAfter(string $letters, bool $extended): bool
    {
        $on = true;
        foreach (str_split($letters) as $letter) {
            if ('^' === $letter) {
                $extended = false;
            } elseif ('-' === $letter) {
                $on = false;
            } elseif ('x' === $letter) {
                $extended = $on;
            }
        }

        return $extended;
    }

    /**
     * The offset of the ] that closes the class opened at $start: a ] first
     * in the class is a literal, as is everything a backslash escapes.
     */
    private function classEnd(string $body, int $start): int
    {
        $length = \strlen($body);
        $i = $start + 1;
        if ('^' === ($body[$i] ?? '')) {
            $i++;
        }
        if (']' === ($body[$i] ?? '')) {
            $i++;
        }

        for (; $i < $length; $i++) {
            if ('\\' === $body[$i]) {
                $i++;
            } elseif ('[' === $body[$i] && ':' === ($body[$i + 1] ?? '')) {
                $i = $this->posixClassEnd($body, $i) ?? $i;
            } elseif (']' === $body[$i]) {
                return $i;
            }
        }

        return $length;
    }

    /**
     * The offset of the ] of a [:name:] opened at $start, or null when it is
     * no POSIX class: PCRE2 reads one only when no ] comes before the :].
     */
    private function posixClassEnd(string $body, int $start): ?int
    {
        $length = \strlen($body);

        for ($i = $start + 2; $i < $length; $i++) {
            if (':' === $body[$i] && ']' === ($body[$i + 1] ?? '')) {
                return $i + 1;
            }
            if (']' === $body[$i]) {
                return null;
            }
        }

        return null;
    }
}
