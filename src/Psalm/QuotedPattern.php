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

namespace PHPRegex\Psalm;

use PHPRegex\Parser\Analysis\CaptureGroupShape;
use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\RegexParser;

/**
 * A pattern built by concatenating literals and preg_quote() calls whose
 * text is not known, as '/^(\d+)-'.preg_quote($id, '/').'$/'.
 *
 * preg_quote() escapes every character that means something in a pattern,
 * so where the pattern reads the quoted text as literal characters, the
 * text adds no group and changes no option: the groups are those of the
 * pattern with the text read as any string, (?:[\s\S]*). That is not so
 * everywhere: in a class the text is a set of characters, after "{" its
 * digits make a quantifier, after "\" or "(?" it continues an escape or a
 * group, and after "\Q" a quoted "\\E" ends the quoting. The text may also
 * be empty, leaving a quantifier that follows to what precedes it.
 *
 * Each quoted part is read in place with texts that cover these cases, and
 * with no text: the pattern stands for every text only when each probe
 * comes out as literal characters outside a class, with the groups of the
 * pattern unchanged.
 *
 * The pattern stands for the groups only: it is never validated or matched,
 * and a quoted text holding the delimiter makes the real pattern fail to
 * compile.
 *
 * @internal
 */
final class QuotedPattern
{
    /**
     * What a quoted text is read as: any string.
     */
    private const ANY_TEXT = '(?:[\s\S]*)';

    /**
     * Quoted texts as they stand in the pattern, preg_quote() having escaped
     * them: a backslash followed by E, a digit, a letter, a comma, a quote,
     * an escaped character and a NUL.
     */
    private const PROBES = ['\\\\E', '0', 'n', ',', "'", '\.', '\000'];

    /**
     * The pattern standing for every quoted text, or null when some quoted
     * text may change its groups.
     *
     * @param non-empty-list<string|null> $parts the literal parts, null for a quoted text
     */
    public static function of(array $parts, RegexParser $parser): ?string
    {
        // The delimiter opens the first part and closes the last.
        if (!\in_array(null, $parts, true) || null === $parts[0] || null === $parts[\count($parts) - 1] || '' === $parts[0] || 1 === strspn($parts[0], " \t\n\v\f\r", 0, 1)) {
            return null;
        }

        $pattern = implode('', array_map(static fn (?string $part): string => $part ?? self::ANY_TEXT, $parts));
        $groups = self::groups($pattern, $parser);
        if (null === $groups) {
            return null;
        }

        foreach ($parts as $index => $part) {
            if (null === $part && !self::readsLiterally($parts, $index, $groups, $parser)) {
                return null;
            }
        }

        return $pattern;
    }

    /**
     * Whether the quoted text at the index is read as literal characters,
     * whatever it is, with the other quoted texts left empty.
     *
     * @param non-empty-list<string|null> $parts
     * @param array<int, ?string>         $groups
     */
    private static function readsLiterally(array $parts, int $index, array $groups, RegexParser $parser): bool
    {
        $before = implode('', array_map(static fn (?string $part): string => $part ?? '', \array_slice($parts, 0, $index)));
        $after = implode('', array_map(static fn (?string $part): string => $part ?? '', \array_slice($parts, $index + 1)));

        // After an empty text, a quantifier or a comment binds to what precedes it.
        if (1 === LibraryPcre::match('/^\s*+(?:[*+?{#]|\(\?#)/', $after)) {
            return false;
        }

        // Offsets in the tree count from the character after the delimiter.
        $start = \strlen($before) - 1;
        foreach (['', ...self::PROBES] as $probe) {
            if ($groups !== self::groups($before.$probe.$after, $parser)) {
                return false;
            }

            if ('' !== $probe && !self::literalBetween($parser->parse($before.$probe.$after), $start, $start + \strlen($probe), false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the characters between the offsets are read as literals
     * outside a class, each by a node of its own.
     */
    private static function literalBetween(NodeInterface $node, int $start, int $end, bool $inClass): bool
    {
        $covered = 0;
        $stack = [[$node, $inClass]];
        while ([] !== $stack) {
            [$current, $inClass] = array_pop($stack);
            if ($current->getEndPosition() <= $start || $current->getStartPosition() >= $end) {
                continue;
            }

            $children = $current->getChildren();
            if ([] !== $children) {
                foreach ($children as $child) {
                    $stack[] = [$child, $inClass || $current instanceof CharClassNode];
                }

                continue;
            }

            if ($inClass || (!$current instanceof LiteralNode && !$current instanceof CharLiteralNode) || $current->getStartPosition() < $start || $current->getEndPosition() > $end) {
                return false;
            }

            $covered += $current->getEndPosition() - $current->getStartPosition();
        }

        return $covered === $end - $start;
    }

    /**
     * The name of each group, by number, or null when the library cannot
     * read the pattern.
     *
     * @return array<int, ?string>|null
     */
    private static function groups(string $pattern, RegexParser $parser): ?array
    {
        try {
            $shape = (new CaptureShapeAnalyzer($parser->target()))->analyze($parser->parse($pattern));
        } catch (ExceptionInterface) {
            return null;
        }

        return array_map(static fn (CaptureGroupShape $group): ?string => $group->name, $shape->groups);
    }
}
