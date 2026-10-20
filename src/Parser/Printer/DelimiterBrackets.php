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

namespace PHPRegex\Parser\Printer;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Syntax\PatternParser;
use PHPRegex\Parser\Text\Ascii;

/**
 * Where PHP ends a delimited pattern, as a printed body must keep it.
 *
 * PHP does not read a body the way PCRE does. It pairs each backslash with
 * the character after it, where PCRE reads "\c\" as one control character,
 * and under a bracket delimiter it counts the brackets it does not see
 * escaped, those of a comment, a verb name or a "\Q...\E" run included.
 * A printer that drops a comment, respells a quoted run or escapes a literal
 * bracket can leave PHP ending the pattern elsewhere than PCRE reads its
 * end. The printer writes the body as PCRE reads it; these functions decide
 * which brackets it keeps as written and fix up the rest, from the source,
 * the tree and the delimiters alone.
 *
 * @internal
 */
final class DelimiterBrackets
{
    /**
     * The "\Q...\E" regions of a pattern's source, as offsets: start, end.
     * A comment, a verb name or a callout string holds text, where a "\Q"
     * opens no quote, and only a backslash that starts an escape opens one:
     * "\\Q" is an escaped backslash, then "Q", and "\c\Q" is
     * control-backslash, then "Q".
     *
     * @return list<array{int, int}>
     */
    public static function quotedSpans(string $source, ?NodeInterface $pattern): array
    {
        if (!str_contains($source, '\\')) {
            return [];
        }

        $texts = [];
        if (null !== $pattern) {
            $unused = [];
            self::collectSpans($pattern, '', $texts, $unused);
            usort($texts, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        }

        $spans = [];
        $length = \strlen($source);
        $offset = 0;
        $text = 0;
        $count = \count($texts);

        while (false !== ($start = strpos($source, '\\', $offset))) {
            while ($text < $count && $texts[$text][1] <= $start) {
                $text++;
            }

            if ($text < $count && $texts[$text][0] <= $start) {
                $offset = $texts[$text][1];

                continue;
            }

            // "\c" at the end of the pattern, the letter "c" on PHP 7.3 and
            // 7.4, takes no character after it.
            $escape = $source[$start + 1] ?? '';
            if ('Q' !== $escape) {
                $offset = min($length, $start + ('c' === $escape ? 3 : 2));

                continue;
            }

            $end = strpos($source, '\\E', $start + 2);
            $stop = false === $end ? $length : $end + 2;
            $spans[] = [$start, $stop];
            $offset = $stop;
        }

        return $spans;
    }

    /**
     * The comments a compacted pattern keeps for its bracket delimiter, and
     * the brackets of "\Q...\E" runs a rewritten pattern keeps as written.
     *
     * Some brackets PHP counts take no backslash: those of a verb name or a
     * callout string, "(*MARK:\{)" names "\{", the "<" of "(?<=" and "(?<!",
     * the "[" that opens a class. A comment holding the bracket that pairs,
     * in the source, with one of those stays: a "(?#...)" as written, a "#"
     * comment as a "(?#...)" holding only its brackets, as the rest of its
     * text, a ")" included, may not stand in one; a bracket of either left
     * pairing with nothing goes. Should dropping the other comments still
     * leave such a bracket alone, every bracket of every comment stays,
     * which gives back the balance of the source.
     *
     * A bracket of a "\Q...\E" run is counted too, and would not be once
     * respelled "\{": the run is kept as written where the bracket pairs,
     * among those the output holds, with one that is not quoted; elsewhere
     * it is respelled. Laid out, the pattern keeps every comment.
     *
     * @param string|null           $source        the source the output is written from;
     *                                             null when it is written from the tree
     * @param list<array{int, int}> $quotedSpans   the "\Q...\E" regions of $source (see quotedSpans)
     * @param bool                  $dropsComments whether the output drops comments, as compacting does
     *
     * @return array{array<int, string>, array<int, true>} the comments kept, by
     *                                                     object id, each with the text its "(?#...)" holds, and the
     *                                                     source offsets of the quoted brackets kept as written
     */
    public static function asWritten(RegexNode $node, ?string $source, array $quotedSpans, bool $dropsComments): array
    {
        $opening = $node->delimiter;
        $closing = PatternParser::closingDelimiter($opening);
        if ($opening === $closing || null === $source || (!$dropsComments && [] === $quotedSpans)) {
            return [[], []];
        }

        $tokens = self::countedBrackets($node->pattern, $source, $opening, $closing, $quotedSpans);
        if ($dropsComments && !self::holdsBracketWrittenAsIs($tokens)) {
            return [[], []];
        }

        $whole = self::commentsPairedWithBracketsAsWritten($tokens);
        $all = !$dropsComments;
        $quoted = self::quotedKept($tokens, $all, $whole);
        $alone = [];
        $trimmed = [];
        if (!$all) {
            $unpaired = self::unpairedCommentBrackets($tokens, $quoted, $whole);
            if (null === $unpaired) {
                $all = true;
                $quoted = self::quotedKept($tokens, $all, $whole);
            } else {
                [$alone, $trimmed] = $unpaired;
            }
        }

        return [$dropsComments ? self::keptComments($tokens, $all, $whole, $alone, $trimmed, $opening, $closing) : [], $quoted];
    }

    /**
     * Escape the delimiter brackets PHP would not pair once comments are
     * dropped, "{a(?#{)b}c}" compacted for one: PHP counts the brackets of
     * a body, skipping the escaped ones, to find where it ends.
     */
    public static function balanced(string $body, string $opening, string $closing): string
    {
        $open = [];
        $unpaired = [];
        $length = \strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $character = $body[$i];
            if ('\\' === $character) {
                $i += self::escapeLength($body, $i) - 1;
            } elseif ($closing === $character) {
                if ([] === $open) {
                    $unpaired[] = $i;
                } else {
                    array_pop($open);
                }
            } elseif ($opening === $character) {
                $open[] = $i;
            }
        }

        $unpaired = array_merge($unpaired, $open);
        rsort($unpaired);
        foreach ($unpaired as $offset) {
            $body = substr_replace($body, '\\', $offset, 0);
        }

        return $body;
    }

    /**
     * A body pretty printing wrote with $mark for the backslash of each
     * literal bracket of the delimiter (followed by "Q" where the bracket
     * cannot stand unescaped, "(", ")" and "[" outside a class, "]" in one),
     * with each mark resolved. PHP counts the brackets of the delimiter
     * written as is, a comment's, a name's, a group's or a class's, wherever
     * they stand. Those pair among themselves first; a marked literal
     * bracket then comes back counted where it pairs with one left alone,
     * and escaped otherwise, which PHP does not count. Counted, it stands
     * unescaped, or quoted, "\Q(\E", where unescaped it would mean something
     * else: a "(", a ")" or a "[" outside a class, a "]" in one, a "{" that
     * would open a repeat, "{2}", "{,3}" or "{ 1, 2 }", or end an escape,
     * "\x{", a "[" before ":", "." or "=", which would open a POSIX class.
     *
     * @param non-empty-string $mark
     */
    public static function markedBalanced(string $body, string $mark, string $opening, string $closing): string
    {
        $tokens = [];
        $bare = [];
        $length = \strlen($body);
        $markLength = \strlen($mark);
        $repeat = '/\G\{[ \t]*+(?:[0-9]++[ \t]*+(?:,[ \t]*+[0-9]*+[ \t]*+)?|,[ \t]*+[0-9]++[ \t]*+)(?:'.preg_quote($mark, '/').'Q?)?\}/';
        $i = 0;
        while ($i < $length) {
            if (0 === substr_compare($body, $mark, $i, $markLength)) {
                $at = $i + $markLength;
                $quoted = 'Q' === $body[$at];
                $bracket = $body[$quoted ? $at + 1 : $at];
                $bare[$i] = !$quoted && match ($bracket) {
                    '{' => 1 !== LibraryPcre::match($repeat, $body, $matches, 0, $at)
                        && !($i >= 2 && '\\' === $body[$i - 2] && Ascii::isAlpha($body[$i - 1])),
                    '[' => !str_contains(':.=', $body[$at + 1] ?? ':'),
                    default => true,
                };
                $tokens[] = [$i, $opening === $bracket, true];
                $i = $quoted ? $at + 2 : $at + 1;

                continue;
            }

            // A backslash and the character it escapes are one step.
            $character = $body[$i];
            if ('\\' === $character) {
                $i += self::escapeLength($body, $i);

                continue;
            }

            if ($opening === $character || $closing === $character) {
                $tokens[] = [$i, $opening === $character, false];
            }
            $i++;
        }

        $unescaped = self::countedLiterals($tokens);

        $result = '';
        $from = 0;
        while (false !== ($at = strpos($body, $mark, $from))) {
            $bracketAt = $at + $markLength + ('Q' === $body[$at + $markLength] ? 1 : 0);
            $bracket = $body[$bracketAt];
            $result .= substr($body, $from, $at - $from).match (true) {
                !isset($unescaped[$at]) => '\\'.$bracket,
                $bare[$at] => $bracket,
                default => '\\Q'.$bracket.'\\E',
            };
            $from = $bracketAt + 1;
        }

        return $result.substr($body, $from);
    }

    /**
     * The body spelled so that PHP ends the pattern at the closing delimiter
     * written after it, where PCRE ends it.
     *
     * PHP finds the end of a pattern pairing each backslash with the
     * character after it, where PCRE reads "\c\" as one control character:
     * after it, PHP reads "\\" where PCRE reads a backslash escaping the
     * next character, and "\)" where PCRE reads ")". When the two readings
     * disagree on where the pattern ends, each "\c\" before a backslash or
     * a delimiter is spelled "\x1C", and a delimiter that would then end
     * the pattern is escaped, as PCRE read it as a literal.
     *
     * A body ending in an odd run of backslashes, "\c\" for one, would
     * escape the closing delimiter: an empty "\E", which PCRE ignores,
     * closes the run.
     */
    public static function spelledToEnd(string $body, string $opening, string $closing): string
    {
        $body = self::controlBackslashesSpelled($body, $opening, $closing);

        return 1 === (\strlen($body) - \strlen(rtrim($body, '\\'))) % 2 ? $body.'\\E' : $body;
    }

    /**
     * The brackets of the delimiter PHP counts in the source, in order, each
     * as a token: whether it opens, its owner, its offset, whether a
     * "\Q...\E" run holds it, and where that run starts (-1 when none does).
     *
     * The owner is the comment that holds the bracket; true for a bracket
     * written as is, a name's, a group's, a class's, a repeat's; null for a
     * plain literal bracket, which may take a backslash where PHP would not
     * pair it (see balanced), and for a quoted one. A line break, a tab, a
     * vertical tab or a form feed parts a quoted run the output writes:
     * each part is a run of its own.
     *
     * @param list<array{int, int}> $quotedSpans
     *
     * @return list<array{bool, CommentNode|true|null, int, bool, int}>
     */
    private static function countedBrackets(NodeInterface $pattern, string $source, string $opening, string $closing, array $quotedSpans): array
    {
        $spans = [];
        $literals = [];
        self::collectSpans($pattern, $opening.$closing, $spans, $literals);
        usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $tokens = [];
        $span = 0;
        $quote = 0;
        $count = \count($spans);
        $quotes = \count($quotedSpans);
        $length = \strlen($source);
        $stops = "\\\n\r\t\v\f".$opening.$closing;
        $break = -1;
        for ($i = strcspn($source, $stops); $i < $length; $i += 1 + strcspn($source, $stops, $i + 1)) {
            if (str_contains("\n\r\t\v\f", $source[$i])) {
                $break = $i;

                continue;
            }

            if ('\\' === $source[$i]) {
                $i++;

                continue;
            }

            while ($span < $count && $spans[$span][1] <= $i) {
                $span++;
            }

            while ($quote < $quotes && $quotedSpans[$quote][1] <= $i) {
                $quote++;
            }

            $owner = $span < $count && $spans[$span][0] <= $i ? $spans[$span][2] : null;
            $quoted = null === $owner && $quote < $quotes && $quotedSpans[$quote][0] <= $i;
            if (null === $owner && !$quoted && !isset($literals[$i])) {
                $owner = true;
            }

            $tokens[] = [$opening === $source[$i], $owner, $i, $quoted, $quoted ? max($quotedSpans[$quote][0], $break) : -1];
        }

        return $tokens;
    }

    /**
     * Whether a bracket written as is stands among the tokens: without one,
     * no comment holds a bracket the output needs.
     *
     * @param list<array{bool, CommentNode|true|null, int, bool, int}> $tokens
     */
    private static function holdsBracketWrittenAsIs(array $tokens): bool
    {
        foreach ($tokens as [, $owner]) {
            if (true === $owner) {
                return true;
            }
        }

        return false;
    }

    /**
     * The comments that hold the partner of a bracket written as is, by
     * object id. Such a comment keeps all of its brackets, a "#" one as a
     * "(?#...)" does: those it would drop could leave the others paired
     * elsewhere.
     *
     * @param list<array{bool, CommentNode|true|null, int, bool, int}> $tokens
     *
     * @return array<int, true>
     */
    private static function commentsPairedWithBracketsAsWritten(array $tokens): array
    {
        $whole = [];
        $partners = self::bracketPartners(array_column($tokens, 0));
        foreach ($tokens as $index => [, $owner]) {
            $partner = $partners[$index];
            $comment = null === $partner ? null : $tokens[$partner][1];
            if (true === $owner && $comment instanceof CommentNode) {
                $whole[spl_object_id($comment)] = true;
            }
        }

        return $whole;
    }

    /**
     * Whether the output holds a bracket of this owner: every bracket but a
     * comment's, and a comment's when every comment stays or when it pairs
     * with a bracket written as is.
     *
     * @param array<int, true> $whole the comments paired with a bracket written as is
     */
    private static function stays(CommentNode|bool|null $owner, bool $all, array $whole): bool
    {
        return !$owner instanceof CommentNode || $all || isset($whole[spl_object_id($owner)]);
    }

    /**
     * The source offsets of the quoted brackets kept as written.
     *
     * Paired as PHP pairs the brackets the output holds, a quoted one is
     * respelled where it stands alone or with another quoted one: taking
     * away pairs whose two brackets go leaves every other pair as it was.
     * Kept, it pairs with a bracket written as is, or with a literal one
     * balanced leaves alone. A run is written whole: kept, it keeps each of
     * its brackets, and each one's quoted partner keeps its own run.
     *
     * @param list<array{bool, CommentNode|true|null, int, bool, int}> $tokens
     * @param array<int, true>                                         $whole
     *
     * @return array<int, true>
     */
    private static function quotedKept(array $tokens, bool $all, array $whole): array
    {
        $present = [];
        foreach ($tokens as $token) {
            if ($token[3] || self::stays($token[1], $all, $whole)) {
                $present[] = $token;
            }
        }

        $runs = [];
        $pending = [];
        $partners = self::bracketPartners(array_column($present, 0));
        foreach ($present as $index => [, , , $quoted, $run]) {
            if ($quoted) {
                $runs[$run][] = $index;
                $partner = $partners[$index];
                if (null !== $partner && !$present[$partner][3]) {
                    $pending[] = $run;
                }
            }
        }

        $keptRuns = [];
        while (null !== ($run = array_pop($pending))) {
            if (isset($keptRuns[$run])) {
                continue;
            }

            $keptRuns[$run] = true;
            foreach ($runs[$run] as $index) {
                $partner = $partners[$index];
                if (null !== $partner && $present[$partner][3]) {
                    $pending[] = $present[$partner][4];
                }
            }
        }

        $kept = [];
        foreach ($present as [, , $offset, $quoted, $run]) {
            if ($quoted && isset($keptRuns[$run])) {
                $kept[$offset] = true;
            }
        }

        return $kept;
    }

    /**
     * The comment brackets left pairing with nothing once the other
     * comments are dropped, by token index, and the comments holding them,
     * by object id: such a bracket goes, which leaves every other pair as it
     * was, and its comment keeps only the brackets that pair. Null when a
     * bracket written as is, or one of a quoted run kept whole, is left
     * alone: every comment bracket then stays.
     *
     * @param list<array{bool, CommentNode|true|null, int, bool, int}> $tokens
     * @param array<int, true>                                         $quoted the quoted brackets kept, by source offset
     * @param array<int, true>                                         $whole
     *
     * @return array{array<int, true>, array<int, true>}|null
     */
    private static function unpairedCommentBrackets(array $tokens, array $quoted, array $whole): ?array
    {
        $left = [];
        foreach ($tokens as $index => [$isOpening, $owner, $offset, $isQuoted]) {
            if ($isQuoted ? isset($quoted[$offset]) : self::stays($owner, false, $whole)) {
                $left[] = [$isOpening, $owner, $index, $isQuoted];
            }
        }

        $partners = self::bracketPartners(array_column($left, 0));
        foreach ($left as $index => [, $owner, , $isQuoted]) {
            if ((true === $owner || $isQuoted) && null === $partners[$index]) {
                return null;
            }
        }

        $alone = [];
        $trimmed = [];
        foreach ($left as $index => [, $owner, $token]) {
            if ($owner instanceof CommentNode && null === $partners[$index]) {
                $alone[$token] = true;
                $trimmed[spl_object_id($owner)] = true;
            }
        }

        return [$alone, $trimmed];
    }

    /**
     * The comments that stay, by object id, each with the text its
     * "(?#...)" holds: a "(?#...)" as written, a "#" comment or a comment
     * that lost a bracket as the brackets it keeps.
     *
     * @param list<array{bool, CommentNode|true|null, int, bool, int}> $tokens
     * @param array<int, true>                                         $whole
     * @param array<int, true>                                         $alone   the comment brackets that go, by token index
     * @param array<int, true>                                         $trimmed the comments that lose one, by object id
     *
     * @return array<int, string>
     */
    private static function keptComments(array $tokens, bool $all, array $whole, array $alone, array $trimmed, string $opening, string $closing): array
    {
        $kept = [];
        foreach ($tokens as $index => [$isOpening, $owner]) {
            if (!$owner instanceof CommentNode || !self::stays($owner, $all, $whole) || isset($alone[$index])) {
                continue;
            }

            $id = spl_object_id($owner);
            if (!$owner->extended && !isset($trimmed[$id])) {
                $kept[$id] = strtr($owner->comment, "\r\n", '  ');
            } else {
                $kept[$id] = ($kept[$id] ?? '').($isOpening ? $opening : $closing);
            }
        }

        return $kept;
    }

    /**
     * The marked literal brackets that come back counted, by offset: the
     * brackets written as is pair among themselves first; a literal opening
     * one then goes with a closing one left alone after it, a literal
     * closing one with an opening one left alone before.
     *
     * @param list<array{int, bool, bool}> $tokens each bracket's offset, whether
     *                                             it opens, whether it is a marked literal
     *
     * @return array<int, true>
     */
    private static function countedLiterals(array $tokens): array
    {
        $fixed = array_keys(array_filter($tokens, static fn (array $token): bool => !$token[2]));
        $fixedPartners = self::bracketPartners(array_map(static fn (int $index): bool => $tokens[$index][1], $fixed));
        $alone = [];
        foreach ($fixed as $position => $index) {
            if (null === $fixedPartners[$position]) {
                $alone[$index] = true;
            }
        }

        $unescaped = [];
        $literalOpenings = [];
        $openingsAlone = 0;
        foreach ($tokens as $index => [$offset, $isOpening, $isLiteral]) {
            if ($isLiteral && $isOpening) {
                $literalOpenings[] = $offset;
            } elseif ($isLiteral && $openingsAlone > 0) {
                $openingsAlone--;
                $unescaped[$offset] = true;
            } elseif (isset($alone[$index]) && $isOpening) {
                $openingsAlone++;
            } elseif (isset($alone[$index]) && [] !== $literalOpenings) {
                $unescaped[array_pop($literalOpenings)] = true;
            }
        }

        return $unescaped;
    }

    /**
     * Collect, in the order of the tree, the spans of the comments, with the
     * comment, and of the verbs and string callouts, with true: a "\Q" in a
     * name opens no quote. Collect too the offsets of the plain literals
     * that are one of $brackets.
     *
     * @param list<array{int, int, CommentNode|true}> $spans
     * @param array<int, true>                        $literals
     */
    private static function collectSpans(NodeInterface $node, string $brackets, array &$spans, array &$literals): void
    {
        if ($node instanceof CommentNode) {
            $spans[] = [$node->getStartPosition(), $node->getEndPosition(), $node];

            return;
        }

        if ($node instanceof PcreVerbNode || ($node instanceof CalloutNode && \is_string($node->identifier))) {
            $spans[] = [$node->getStartPosition(), $node->getEndPosition(), true];

            return;
        }

        if ($node instanceof LiteralNode && 1 === \strlen($node->value) && str_contains($brackets, $node->value)) {
            $literals[$node->getStartPosition()] = true;
        }

        if (!$node instanceof ExtendedCharClassNode) {
            foreach ($node->getChildren() as $child) {
                self::collectSpans($child, $brackets, $spans, $literals);
            }
        }
    }

    /**
     * Pair brackets as balanced does, a closing one with the last opening
     * one still open: for each, the index of its partner, or null.
     *
     * @param array<int, bool> $opens whether each bracket opens
     *
     * @return array<int, int|null>
     */
    private static function bracketPartners(array $opens): array
    {
        $partners = [];
        $open = [];
        foreach ($opens as $index => $isOpening) {
            $partners[$index] = null;
            if ($isOpening) {
                $open[] = $index;
            } elseif ([] !== $open) {
                $partner = array_pop($open);
                $partners[$index] = $partner;
                $partners[$partner] = $index;
            }
        }

        return $partners;
    }

    /**
     * The length of the escape a backslash at $offset opens, as PCRE reads
     * it: two characters, or three for "\c", which takes the next one, a
     * backslash included.
     */
    private static function escapeLength(string $body, int $offset): int
    {
        return 'c' === ($body[$offset + 1] ?? '') ? 3 : 2;
    }

    /**
     * Spell each "\c\" before a backslash or a delimiter "\x1C", where PHP
     * and PCRE disagree on where the body ends (see spelledToEnd).
     */
    private static function controlBackslashesSpelled(string $body, string $opening, string $closing): string
    {
        if (self::endsWhereWritten($body, $opening, $closing)) {
            return $body;
        }

        $spelled = '';
        $length = \strlen($body);
        for ($i = 0; $i < $length; $i++) {
            if ('\\' !== $body[$i]) {
                $spelled .= $body[$i];

                continue;
            }

            $next = $body[$i + 3] ?? '';
            if ('c' === ($body[$i + 1] ?? '') && '\\' === ($body[$i + 2] ?? '') && '' !== $next && str_contains('\\'.$opening.$closing, $next)) {
                $spelled .= '\\x1C'.($opening === $closing && '\\' !== $next ? '\\' : '');
                $i += 2;

                continue;
            }

            $escape = self::escapeLength($body, $i);
            $spelled .= substr($body, $i, $escape);
            $i += $escape - 1;
        }

        return $spelled;
    }

    /**
     * Whether PHP, pairing each backslash with the character after it, reads
     * the whole body before the closing delimiter: no closing delimiter
     * stands bare in it, or, for brackets, every one pairs.
     */
    private static function endsWhereWritten(string $body, string $opening, string $closing): bool
    {
        $depth = 0;
        $length = \strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $character = $body[$i];
            if ('\\' === $character) {
                $i++;
            } elseif ($closing === $character) {
                if ($opening === $closing || 0 === $depth) {
                    return false;
                }

                $depth--;
            } elseif ($opening === $character) {
                $depth++;
            }
        }

        return 0 === $depth;
    }
}
