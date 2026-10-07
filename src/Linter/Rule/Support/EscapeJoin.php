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

namespace PHPRegex\Linter\Rule\Support;

use PHPRegex\Linter\Rule\LintContext;
use PHPRegex\Parser\Internal\Ascii;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\NodeInterface;

/**
 * Whether taking the parentheses of a group out, or the brackets of a
 * class, would join what it holds with the text around it into another
 * token: an escape and the digit after it ("(a)\1(?:)0" is not "(a)\10",
 * "\01(?:)2" is not the newline "\012", "\xa(?:b)" is not "\xab"), or
 * braces and a count into a quantifier ("a{(?:)2}" reads "{2}" literally,
 * "a{2}" repeats the "a").
 *
 * @internal
 */
final class EscapeJoin
{
    /**
     * An opening brace a count may follow: "{", "{2", "{2," or "{,", spaces
     * allowed, the brace not escaped.
     */
    private const OPEN_BRACES = '/(?<!\\\\)(?:\\\\\\\\)*+\{[ \t]*+(?:[0-9]++[ \t]*+)?(?:,[ \t]*+(?:[0-9]++[ \t]*+)?)?\z/';

    /**
     * What may go on in an open brace: a digit, a comma or the closing
     * brace, after spaces.
     */
    private const BRACE_CONTINUATION = '/^[ \t]*+[0-9,}]/';

    /**
     * A "\N", not escaped, at the end of the text.
     */
    private const MATCH_ANYTHING_ESCAPE = '/(?<!\\\\)(?:\\\\\\\\)*+\\\\N\z/';

    /**
     * A count in braces: "{2}", "{2,}", "{2,3}" or "{,3}", spaces allowed.
     */
    private const COUNT = '/^\{[ \t]*+(?:[0-9]++[ \t]*+(?:,[ \t]*+(?:[0-9]++[ \t]*+)?)?|,[ \t]*+[0-9]++[ \t]*+)\}/';

    private function __construct() {}

    /**
     * Whether the group keeps what it holds apart from the text on either
     * side of it: the text before the group and its start, its end and
     * the text after it, or, for an empty group, the text on either side.
     */
    public static function groupSeparatesAnEscape(GroupNode $group, LintContext $context): bool
    {
        return self::separates($group, self::text($group->child, $context->pattern->source), $context);
    }

    /**
     * Whether writing $inside in place of the node, as the pattern writes
     * it, joins $inside with the text before or after the node.
     */
    public static function separates(NodeInterface $node, string $inside, LintContext $context): bool
    {
        $source = $context->pattern->source;
        $before = substr($source, 0, max(0, $node->getStartPosition()));
        $after = substr($source, max(0, $node->getEndPosition()));

        if ('' === $inside) {
            return self::extends($before, $after);
        }

        return self::extends($before, $inside) || self::extends($inside, $after);
    }

    /**
     * Whether the text ends with a token the next text would make longer:
     * a reference or octal escape ("\1", "\g-1", "\0") before a digit, a
     * "\x" with fewer than two digits before a hex digit, an open brace
     * before a count, a comma or the closing brace, or "\N" before braces
     * that hold no count: "\N{U+41}" is a code point under UTF mode, and
     * does not compile without it.
     */
    public static function extends(string $text, string $next): bool
    {
        if ('' === $next) {
            return false;
        }

        if (str_starts_with($next, '{') && 1 === LibraryPcre::match(self::MATCH_ANYTHING_ESCAPE, $text)) {
            return 1 !== LibraryPcre::match(self::COUNT, $next);
        }

        if (1 === LibraryPcre::match(self::OPEN_BRACES, $text) && 1 === LibraryPcre::match(self::BRACE_CONTINUATION, $next)) {
            return true;
        }

        if (1 !== LibraryPcre::match('/(?<!\\\\)(?:\\\\\\\\)*+\\\\(?<escape>[0-9]+|g[+-]?[0-9]+|x[0-9A-Fa-f]?)\z/', $text, $matches)) {
            return false;
        }

        return str_starts_with($matches['escape'], 'x') ? Ascii::isHexDigit($next[0]) : Ascii::isDigit($next[0]);
    }

    private static function text(NodeInterface $node, string $source): string
    {
        $start = $node->getStartPosition();
        $length = $node->getEndPosition() - $start;

        return $start >= 0 && $length > 0 ? substr($source, $start, $length) : '';
    }
}
