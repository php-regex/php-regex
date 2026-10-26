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
use PHPRegex\Parser\Syntax\Delimiters;
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
     * After a mark, a literal bracket written unescaped (see rebalanced).
     */
    public const MARK_BARE = 'B';

    /**
     * After a mark, a literal bracket written escaped that may only come
     * back counted quoted, "\Q(\E" (see rebalanced).
     */
    public const MARK_QUOTED = 'Q';

    /**
     * After a mark, the opening of a lookbehind or an atomic group written
     * with its symbol (see rebalanced).
     */
    public const MARK_GROUP = 'G';

    /**
     * After a mark, a bracket a collapsed comment may hold, which it writes
     * only where one PHP counts needs it (see rebalanced).
     */
    public const MARK_COMMENT = 'C';

    /**
     * The symbols of the lookbehinds and the atomic group, each holding a
     * "<" or a ">", with the name that holds none: PCRE2 reads both from
     * 10.34 on.
     */
    public const NAMED_OPENINGS = ['(?<=' => '(*plb:', '(?<!' => '(*nlb:', '(?<*' => '(*naplb:', '(?>' => '(*atomic:'];

    private const KIND_FIXED = 0;

    private const KIND_LITERAL = 1;

    private const KIND_QUOTED = 2;

    private const KIND_GROUP = 3;

    private const KIND_COMMENT = 4;

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
        $closing = Delimiters::closing($opening);
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
                $standsBare = match ($bracket) {
                    '{' => 1 !== LibraryPcre::match($repeat, $body, $matches, 0, $at)
                        && !($i >= 2 && '\\' === $body[$i - 2] && Ascii::isAlpha($body[$i - 1])),
                    '[' => !str_contains(':.=', $body[$at + 1] ?? ':'),
                    default => true,
                };
                $bare[$i] = !$quoted && $standsBare;
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
            $spelling = match (true) {
                !isset($unescaped[$at]) => '\\'.$bracket,
                $bare[$at] => $bracket,
                default => '\\Q'.$bracket.'\\E',
            };
            $result .= substr($body, $from, $at - $from).$spelling;
            $from = $bracketAt + 1;
        }

        return $result.substr($body, $from);
    }

    /**
     * A body written with $mark before each literal bracket of the delimiter
     * and before each symbol of a lookbehind or an atomic group that holds
     * one, spelled so that PHP ends the pattern at the closing delimiter;
     * null when no spelling of the marked items can.
     *
     * PHP counts each bracket of the delimiter it does not see escaped. The
     * brackets nothing marks, a group's, a class's, a comment's, a name's,
     * are counted as written. After the mark, a literal bracket is written
     * unescaped ("B" then the bracket), escaped (the bracket alone) or
     * escaped where it may only come back counted quoted ("Q" then the
     * bracket); "C" then a bracket marks one a collapsed comment may hold,
     * which it leaves out unless needed; "G" then the symbol marks a
     * group. Each stays as written
     * unless PHP would then end the pattern too soon, at a closing bracket
     * with nothing open, or never, past an opening one nothing closes.
     * There a literal or comment bracket nearby changes, counted or not, before a
     * group takes its name, "(*plb:" for "(?<=", which holds no bracket:
     * read left to right, a closing bracket with nothing open turns the
     * nearest marked item before it; read right to left, an opening one
     * nothing closes turns the nearest marked item after it.
     *
     * A literal bracket comes back counted unescaped where it means the same
     * there, quoted otherwise, "\Q{\E" (see markedBalanced). "\c\" is
     * spelled "\x1C" first, so that PHP pairs each backslash as PCRE does.
     *
     * @param non-empty-string $mark
     */
    public static function rebalanced(string $body, string $mark, string $opening, string $closing): ?string
    {
        $body = self::controlBackslashesSpelledAll($body);
        $tokens = self::markedTokens($body, $mark, $opening, $closing);
        $order = array_keys($tokens);
        $included = self::pairing($tokens, $order, false) ?? self::pairing($tokens, $order, true);
        if (null === $included) {
            return null;
        }

        $result = '';
        $from = 0;
        foreach ($tokens as $index => [$start, $end, $bracket, , $kind]) {
            if (self::KIND_FIXED === $kind) {
                continue;
            }

            $spelling = match (true) {
                self::KIND_GROUP === $kind => self::groupSpelling(substr($body, $start + \strlen($mark) + 1, $end - $start - \strlen($mark) - 1), $included[$index]),
                self::KIND_COMMENT === $kind => $included[$index] ? $bracket : '',
                !$included[$index] => '\\'.$bracket,
                self::KIND_LITERAL === $kind && self::standsBare($body, $start, $end, $bracket, $mark) => $bracket,
                default => '\\Q'.$bracket.'\\E',
            };
            $result .= substr($body, $from, $start - $from).$spelling;
            $from = $end;
        }

        return $result.substr($body, $from);
    }

    /**
     * Whether PHP, pairing each backslash with the character after it, reads
     * the whole body before the closing delimiter: no closing delimiter
     * stands bare in it, or, for brackets, every one pairs.
     */
    public static function endsWhereWritten(string $body, string $opening, string $closing): bool
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
     * pair it (see rebalanced), and for a quoted one. A line break, a tab, a
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
     * rebalanced leaves alone. A run is written whole: kept, it keeps each of
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
     * Pair brackets as PHP counts them, a closing one with the last opening
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
     * Every "\c\" spelled "\x1C": PHP then pairs each backslash with the
     * character after it as PCRE does.
     */
    private static function controlBackslashesSpelledAll(string $body): string
    {
        if (!str_contains($body, '\\c\\')) {
            return $body;
        }

        $spelled = '';
        $length = \strlen($body);
        for ($i = 0; $i < $length; $i++) {
            if ('\\' !== $body[$i]) {
                $spelled .= $body[$i];

                continue;
            }

            $escape = substr($body, $i, self::escapeLength($body, $i));
            $spelled .= '\\c\\' === $escape ? '\\x1C' : $escape;
            $i += \strlen($escape) - 1;
        }

        return $spelled;
    }

    /**
     * The brackets of the delimiter a marked body holds, in order, each as
     * its start, its end, the bracket, whether it opens, its kind (one of
     * the KIND_* constants) and whether PHP counts it as written.
     *
     * @param non-empty-string $mark
     *
     * @return list<array{int, int, string, bool, int, bool}>
     */
    private static function markedTokens(string $body, string $mark, string $opening, string $closing): array
    {
        $tokens = [];
        $length = \strlen($body);
        $markLength = \strlen($mark);
        $i = 0;
        while ($i < $length) {
            if (0 === substr_compare($body, $mark, $i, $markLength)) {
                $at = $i + $markLength;
                $flag = $body[$at] ?? '';
                if (self::MARK_GROUP === $flag) {
                    $symbol = self::groupSymbolAt($body, $at + 1);
                    $bracket = str_contains($symbol, $opening) ? $opening : $closing;
                    $tokens[] = [$i, $at + 1 + \strlen($symbol), $bracket, $opening === $bracket, self::KIND_GROUP, true];
                    $i = $at + 1 + \strlen($symbol);

                    continue;
                }

                $kind = match ($flag) {
                    self::MARK_QUOTED => self::KIND_QUOTED,
                    self::MARK_COMMENT => self::KIND_COMMENT,
                    default => self::KIND_LITERAL,
                };
                $flagged = self::MARK_BARE === $flag || self::KIND_LITERAL !== $kind;
                $bracket = $body[$flagged ? $at + 1 : $at];
                $end = $flagged ? $at + 2 : $at + 1;
                $tokens[] = [$i, $end, $bracket, $opening === $bracket, $kind, self::MARK_BARE === $flag];
                $i = $end;

                continue;
            }

            $character = $body[$i];
            if ('\\' === $character) {
                $i += 2;

                continue;
            }

            if ($opening === $character || $closing === $character) {
                $tokens[] = [$i, $i + 1, $character, $opening === $character, self::KIND_FIXED, true];
            }
            $i++;
        }

        return $tokens;
    }

    /**
     * The symbol of a group a mark stands before.
     */
    private static function groupSymbolAt(string $body, int $offset): string
    {
        foreach (array_keys(self::NAMED_OPENINGS) as $symbol) {
            if (0 === substr_compare($body, $symbol, $offset, \strlen($symbol))) {
                return $symbol;
            }
        }

        return '';
    }

    /**
     * Whether each token is counted once the marked items are turned so
     * that every bracket PHP counts pairs, read left to right then right to
     * left (see paired); null when nothing can turn. A group with nothing to
     * pair takes its name first where $groupsFirst, rather than use up a
     * literal bracket a bracket nothing marks may need later.
     *
     * @param list<array{int, int, string, bool, int, bool}> $tokens
     * @param list<int>                                      $order
     *
     * @return array<int, bool>|null
     */
    private static function pairing(array $tokens, array $order, bool $groupsFirst): ?array
    {
        $included = array_column($tokens, 5);

        return self::paired($tokens, $included, $order, true, $groupsFirst) && self::paired($tokens, $included, array_reverse($order), false, $groupsFirst)
            ? $included
            : null;
    }

    /**
     * Turn marked items so that, read in $order, no closing bracket stands
     * with nothing open: read right to left, an opening bracket is the one
     * that closes. A closing bracket with nothing open goes uncounted when
     * it is a literal; otherwise the nearest literal before it turns, an
     * opening one counted or a closing one uncounted, else the group it
     * opens or closes takes its name, first where $groupsFirst, else the
     * nearest group before it. False when nothing can turn.
     *
     * @param list<array{int, int, string, bool, int, bool}> $tokens
     * @param array<int, bool>                               $included whether each token is counted
     * @param list<int>                                      $order
     */
    private static function paired(array $tokens, array &$included, array $order, bool $forward, bool $groupsFirst): bool
    {
        $depth = 0;
        $literals = [];
        $groups = [];
        foreach ($order as $index) {
            [, , , $isOpening, $kind] = $tokens[$index];
            $opens = $forward === $isOpening;
            if (!$included[$index]) {
                if ($opens) {
                    $literals[] = $index;
                }

                continue;
            }

            if ($opens) {
                $depth++;

                continue;
            }

            if ($depth > 0) {
                $depth--;
                if (self::KIND_GROUP === $kind) {
                    $groups[] = $index;
                } elseif (self::KIND_FIXED !== $kind) {
                    $literals[] = $index;
                }

                continue;
            }

            $turned = self::KIND_FIXED !== $kind && (self::KIND_GROUP !== $kind || $groupsFirst)
                ? $index
                : array_pop($literals) ?? (self::KIND_GROUP === $kind ? $index : array_pop($groups));
            if (null === $turned) {
                return false;
            }

            $included[$turned] = !$included[$turned];
        }

        return true;
    }

    /**
     * A group's symbol, or its name where the symbol's bracket goes.
     */
    private static function groupSpelling(string $symbol, bool $counted): string
    {
        return $counted ? $symbol : self::NAMED_OPENINGS[$symbol] ?? $symbol;
    }

    /**
     * Whether a literal bracket marked from $start to $end means the same
     * unescaped: a "{" opening no repeat nor the braces of an escape before
     * it, a "}" after no "{" that could open a repeat, a "[" before no ":",
     * "." or "=", which would open a POSIX class (see markedBalanced). A
     * mark before the "}" that could close a repeat is read as the bracket
     * it stands before.
     *
     * @param non-empty-string $mark
     */
    private static function standsBare(string $body, int $start, int $end, string $bracket, string $mark): bool
    {
        if ('}' === $bracket) {
            $count = $start;
            while ($count > 0 && str_contains(" \t0123456789,", $body[$count - 1])) {
                $count--;
            }

            return 0 === $count || '{' !== $body[$count - 1];
        }

        return match ($bracket) {
            '{' => 1 !== LibraryPcre::match('/\G[ \t]*+(?:[0-9]++[ \t]*+(?:,[ \t]*+[0-9]*+[ \t]*+)?|,[ \t]*+[0-9]++[ \t]*+)(?:\\\\|'.preg_quote($mark, '/').'[BQ]?)?\}/', $body, $matches, 0, $end)
                && !($start >= 2 && '\\' === $body[$start - 2] && Ascii::isAlpha($body[$start - 1])),
            '[' => !str_contains(':.=', $body[$end] ?? ':'),
            default => true,
        };
    }
}
