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

namespace PHPRegex\Linter\Source;

use PHPRegex\Linter\Extraction\IgnoreMarker;
use PHPRegex\Linter\Extraction\InlineIgnores;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Text\Path;

/**
 * Reads the patterns of Twig's "matches" operator out of the templates
 * under the linted paths: `{% if value matches '/^\d+$/' %}` compiles to a
 * preg_match() of its literal, which Twig reads as PHP reads a
 * double-quoted string would not: every backslash escape is folded
 * (stripcslashes), so '/\d+/' is the pattern /d+/. The pattern is read as
 * Twig hands it to the engine, and linted as any pattern of the project.
 *
 * Found lexically, without Twig: inside `{{ }}` and `{% %}` only, never in
 * a `{# #}` comment, a `{% verbatim %}` block or the text around the tags;
 * the word "matches" followed by one string literal, single- or
 * double-quoted. A variable, a concatenation (`'/' ~ sep ~ '/'`) or an
 * interpolated string (`"#{...}"`) is no literal, and is not read. A
 * `{# @regex-ignore #}` comment holds the markers a PHP comment does,
 * read the same way: the template's markers follow its patterns, as a PHP
 * file's do, and the lint applies and reports them alike.
 *
 * @internal
 */
final class TwigTemplatePatternSource implements PatternSourceInterface
{
    public const NAME = 'twig';

    private const SOURCE = 'twig:matches';
    private const SUFFIX = '.twig';
    private const OPERATOR = 'matches';

    public function getName(): string
    {
        return self::NAME;
    }

    public function isSupported(): bool
    {
        return true;
    }

    public function extract(PatternSourceContext $context): array
    {
        $occurrences = [];
        foreach (self::templates($context) as $file) {
            $content = @file_get_contents($file);
            if (false === $content) {
                $occurrences[] = new PatternOccurrence('', $file, 1, self::SOURCE, unread: 'The template could not be read.');

                continue;
            }

            $occurrences = [...$occurrences, ...self::read($content, $file)];
        }

        return $occurrences;
    }

    /**
     * The patterns of one template, in the order of the template, each at
     * the line and the byte column of its opening quote and silenced when
     * a marker naming no issue covers its line; then, when the template
     * holds any marker, the occurrence carrying them all.
     *
     * @return list<PatternOccurrence>
     */
    public static function read(string $content, string $file): array
    {
        $found = [];
        $ignores = [];
        $length = \strlen($content);
        $i = 0;
        while (false !== ($i = strpos($content, '{', $i)) && $i + 1 < $length) {
            $kind = $content[$i + 1];
            if ('#' === $kind) {
                $end = strpos($content, '#}', $i + 2);
                if (false === $end) {
                    break;
                }

                array_push($ignores, ...self::markers($content, $i, $end));
                $i = $end + 2;

                continue;
            }

            if ('{' !== $kind && '%' !== $kind) {
                $i++;

                continue;
            }

            if ('%' === $kind && 1 === LibraryPcre::match('/\G[-~]?\s*verbatim\s*[-~]?%\}/', $content, $verbatim, 0, $i + 2)) {
                // The block is raw text up to its end tag; without one, to
                // the end of the template.
                $end = LibraryPcre::match('/\{%[-~]?\s*endverbatim\s*[-~]?%\}/', $content, $closing, \PREG_OFFSET_CAPTURE, $i + 2);
                if (1 !== $end || !\is_array($closing[0])) {
                    break;
                }

                $i = (int) $closing[0][1] + \strlen((string) $closing[0][0]);

                continue;
            }

            $i = self::readTag($content, $i + 2, '{' === $kind ? '}}' : '%}', $found);
        }

        $silenced = [];
        foreach ($ignores as $marker) {
            if ([] === $marker->identifiers) {
                $silenced[$marker->coveredLine] = true;
            }
        }

        $occurrences = [];
        foreach ($found as [$offset, $pattern]) {
            $line = self::lineAt($content, $offset);
            $lineStart = strrpos(substr($content, 0, $offset), "\n");

            $occurrences[] = new PatternOccurrence($pattern, $file, $line, self::SOURCE, isIgnored: isset($silenced[$line]), column: $offset - (false === $lineStart ? -1 : $lineStart), fileOffset: $offset);
        }

        if ([] !== $ignores) {
            $occurrences[] = new PatternOccurrence('', $file, 1, self::SOURCE, ignores: $ignores);
        }

        return $occurrences;
    }

    /**
     * The markers of the comment opened by the "{#" at $open and closed by
     * the "#}" at $close, read as those of a PHP "#" comment: the "-" or
     * "~" that may trim the whitespace around it is no part of the marker.
     *
     * @return list<IgnoreMarker>
     */
    private static function markers(string $content, int $open, int $close): array
    {
        $comment = substr($content, $open + 1, $close - $open - 1);
        if (\strlen($comment) > 1 && ('-' === $comment[1] || '~' === $comment[1])) {
            $comment[1] = ' ';
        }

        return InlineIgnores::markersOfComment($comment, self::lineAt($content, $open), $open + 1, $content);
    }

    /**
     * Read the expression of a tag from $start to its closing braces: the
     * patterns found go to $found as [offset of the quote, pattern]. The
     * position after the tag; the end of the template when the tag, or a
     * string in it, never closes.
     *
     * @param list<array{int, string}> $found
     */
    private static function readTag(string $content, int $start, string $close, array &$found): int
    {
        $length = \strlen($content);
        $previous = '';
        $j = $start;
        while ($j < $length) {
            $char = $content[$j];
            if (' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char) {
                $j++;

                continue;
            }

            if ($char === $close[0] && substr($content, $j, 2) === $close) {
                return $j + 2;
            }

            if ("'" === $char || '"' === $char) {
                $end = self::stringEnd($content, $j);
                if (null === $end) {
                    return $length;
                }

                $previous = $char;
                $j = $end + 1;

                continue;
            }

            if (self::startsName($char)) {
                $wordStart = $j;
                while ($j < $length && self::continuesName($content[$j])) {
                    $j++;
                }

                // "foo.matches" is an attribute and "x|matches" a filter:
                // the operator stands on its own.
                if (self::OPERATOR === substr($content, $wordStart, $j - $wordStart) && '.' !== $previous && '|' !== $previous) {
                    $j = self::readOperand($content, $j, $found) ?? $length;
                    if ($j >= $length) {
                        return $length;
                    }

                    $previous = $content[$j - 1];

                    continue;
                }

                $previous = $content[$j - 1];

                continue;
            }

            $previous = $char;
            $j++;
        }

        return $length;
    }

    /**
     * The right operand of a "matches" read at $j: a string literal, whose
     * pattern joins $found unless an operator continues the expression,
     * which makes it a concatenation. The position after the operand, or
     * null when its string never closes.
     *
     * @param list<array{int, string}> $found
     */
    private static function readOperand(string $content, int $j, array &$found): ?int
    {
        $length = \strlen($content);
        while ($j < $length && (' ' === $content[$j] || "\t" === $content[$j] || "\n" === $content[$j] || "\r" === $content[$j])) {
            $j++;
        }

        if ($j >= $length || ("'" !== $content[$j] && '"' !== $content[$j])) {
            return $j;
        }

        $end = self::stringEnd($content, $j);
        if (null === $end) {
            return null;
        }

        $pattern = self::literal(substr($content, $j + 1, $end - $j - 1), $content[$j]);
        $next = $end + 1;
        while ($next < $length && (' ' === $content[$next] || "\t" === $content[$next] || "\n" === $content[$next] || "\r" === $content[$next])) {
            $next++;
        }

        if (null !== $pattern && ($next >= $length || '~' !== $content[$next])) {
            $found[] = [$j, $pattern];
        }

        return $end + 1;
    }

    /**
     * The index of the quote closing the string opened at $quote, a
     * backslash escaping the byte after it; null when it never closes.
     */
    private static function stringEnd(string $content, int $quote): ?int
    {
        $length = \strlen($content);
        for ($k = $quote + 1; $k < $length; $k++) {
            if ('\\' === $content[$k]) {
                $k++;
            } elseif ($content[$k] === $content[$quote]) {
                return $k;
            }
        }

        return null;
    }

    /**
     * The string as Twig reads it: its escapes folded; null for a
     * double-quoted string that interpolates an expression.
     */
    private static function literal(string $inner, string $quote): ?string
    {
        if ('"' === $quote && str_contains($inner, '#{')) {
            return null;
        }

        return stripcslashes($inner);
    }

    private static function lineAt(string $content, int $offset): int
    {
        return 1 + substr_count($content, "\n", 0, $offset);
    }

    private static function startsName(string $char): bool
    {
        return ('a' <= $char && 'z' >= $char) || ('A' <= $char && 'Z' >= $char) || '_' === $char || "\x7f" <= $char;
    }

    private static function continuesName(string $char): bool
    {
        return self::startsName($char) || ('0' <= $char && '9' >= $char);
    }

    /**
     * The templates under the linted paths, sorted: a `.twig` file named
     * as a path, and those under a directory, the excluded ones left out.
     *
     * @return list<string>
     */
    private static function templates(PatternSourceContext $context): array
    {
        $files = [];
        foreach ($context->paths as $path) {
            if ('' === $path) {
                continue;
            }

            if (is_file($path)) {
                if (str_ends_with($path, self::SUFFIX)) {
                    $files[] = Path::fromHost($path);
                }

                continue;
            }

            if (!is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(Path::fromHost($path), \RecursiveDirectoryIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS),
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                $pathname = $file->getPathname();
                if ($file->isFile() && str_ends_with($pathname, self::SUFFIX) && !$context->excludes($pathname)) {
                    $files[] = $pathname;
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files, \SORT_STRING);

        return $files;
    }
}
