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

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Bytes;

/**
 * Where a pattern printed under x breaks into lines.
 *
 * The formatter and pretty printing lay a pattern out over lines where x
 * makes line breaks and indents layout: each group on lines of its own, a
 * run of atoms on its line when it fits, broken after its anchors and its
 * white space and filled up to the width when it does not, one branch per
 * line with its "|" hanging in the indent, "#" comments kept where their
 * author put them and aligned on one column. Where lines break is a
 * concern of its own, with its own state (the indent, the width, the
 * comment already written at the end of a line), kept apart from how each
 * atom is spelled: the layout asks the printer for the text of every node
 * and for what two neighbours need between them, and the printer hands a
 * node over to the layout while the layout is active.
 *
 * One layout serves one rendering of a pattern. It is active while it lays
 * the pattern out, and inactive while a part of it is written on one line,
 * where the indent stays known to what that part writes.
 *
 * @internal
 */
final class PatternLayout
{
    /**
     * What a piece of a run of atoms is to the layout: a character of a
     * word, white space, any other unit, a start or an end anchor, a
     * start-of-pattern option.
     */
    public const PIECE_WORD = 0;

    public const PIECE_SPACE = 1;

    public const PIECE_UNIT = 2;

    public const PIECE_START = 3;

    public const PIECE_END = 4;

    public const PIECE_OPTION = 5;

    /**
     * Where a trailing comment starts on a line, until the comments of
     * consecutive lines are aligned on one column.
     */
    public const TRAIL = "\x01TRAILING-COMMENT\x02";

    private const INDENT = '    ';

    /**
     * Past this many indent levels the rest of a construct stays on one line:
     * a deep nest laid out one group per line would grow past the length the
     * parser reads back.
     */
    private const MAX_INDENT = 10;

    /**
     * A construct nesting more groups than this breaks, even when it fits.
     */
    private const MAX_DEPTH = 2;

    private int $indentLevel;

    /**
     * Whether the sequence about to be visited is the body of a construct
     * that already broke: it breaks too, rather than trying one line again.
     */
    private bool $parentBroke;

    /**
     * A comment already written as the trailing comment of the line before,
     * which its own place in the tree must not write again.
     */
    private ?NodeInterface $skipNode = null;

    /**
     * Whether what was rendered so far wrote a "#" comment, which ends its
     * line.
     */
    private bool $sawLineComment = false;

    /**
     * @param int              $width            the width a line aims at, indent included
     * @param bool             $countsCodePoints whether widths count code points (u, or a
     *                                           leading (*UTF)) or bytes
     * @param string|null      $source           the pattern body the tree was parsed
     *                                           from, which tells where comments stood
     * @param non-empty-string $trail            the marker of a trailing comment: a
     *                                           text the pattern does not hold
     * @param bool             $active           whether the pattern is laid out, from
     *                                           the first indent level on, or written on one line;
     *                                           false too while a part of a laid-out pattern is
     *                                           written on one line, and once it is rendered
     */
    public function __construct(
        private readonly PatternPrinter $printer,
        private readonly int $width,
        private readonly bool $countsCodePoints,
        private readonly ?string $source,
        private readonly string $trail,
        private bool $active,
    ) {
        $this->indentLevel = $this->active ? 1 : 0;
        $this->parentBroke = $this->active;
    }

    /**
     * Whether a construct nests at most two groups, few enough to stay on
     * one line when it fits.
     */
    public static function isShallow(NodeInterface $node): bool
    {
        return self::groupDepth($node) <= self::MAX_DEPTH;
    }

    /**
     * The width of a piece of pattern: code points under UTF, bytes
     * otherwise.
     */
    public static function columnsOf(string $text, bool $countsCodePoints): int
    {
        return $countsCodePoints ? mb_strlen($text, 'UTF-8') : \strlen($text);
    }

    public static function indent(int $level): string
    {
        return str_repeat(self::INDENT, $level);
    }

    public static function isEmpty(NodeInterface $node): bool
    {
        return ($node instanceof LiteralNode && '' === $node->value) || ($node instanceof SequenceNode && [] === $node->children);
    }

    /**
     * Whether a group is a setting, "(?-x)", which holds for the rest of the
     * group around it, rather than a scope, "(?-x:...)", empty or not.
     */
    public static function isSetting(GroupNode $node, ?string $flags = null): bool
    {
        return GroupType::InlineFlags === $node->type
            && $node->getEndPosition() - $node->getStartPosition() === \strlen($flags ?? $node->flags ?? '') + 3;
    }

    public static function isWhiteSpaceCharacter(string $character): bool
    {
        return 1 === \strlen($character) && str_contains(" \t\n\r\v\f", $character);
    }

    /**
     * The last pass over a laid-out pattern. Trailing comments on
     * consecutive lines line up on one column, the way gofmt aligns them.
     * A line ending in an escaped space ends in "\x20" instead, which an
     * editor stripping trailing spaces leaves alone.
     *
     * @param non-empty-string $trail the marker the layout wrote before each trailing comment
     */
    public static function finishLines(string $text, string $trail, bool $countsCodePoints): string
    {
        $lines = explode("\n", $text);
        $count = \count($lines);
        for ($i = 0; $i < $count; $i++) {
            if (!str_contains($lines[$i], $trail)) {
                if (self::endsInEscapedSpace($lines[$i])) {
                    $lines[$i] = substr($lines[$i], 0, -2).'\\x20';
                }

                continue;
            }

            $column = 0;
            for ($j = $i; $j < $count && str_contains($lines[$j], $trail); $j++) {
                $column = max($column, self::columnsOf(explode($trail, $lines[$j], 2)[0], $countsCodePoints));
            }

            for ($k = $i; $k < $j; $k++) {
                [$code, $comment] = explode($trail, $lines[$k], 2) + [1 => ''];
                $lines[$k] = $code.str_repeat(' ', $column - self::columnsOf($code, $countsCodePoints) + 2).$comment;
            }

            $i = $j - 1;
        }

        return implode("\n", $lines);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function indentLevel(): int
    {
        return $this->indentLevel;
    }

    /**
     * Whether a comment was already written as the trailing comment of the
     * line before.
     */
    public function skips(NodeInterface $node): bool
    {
        return $node === $this->skipNode;
    }

    public function noteLineComment(): void
    {
        $this->sawLineComment = true;
    }

    public function sawLineComment(): bool
    {
        return $this->sawLineComment;
    }

    /**
     * Render the body of the whole pattern. Laid out, the start-of-pattern
     * options stay on the line of the opening delimiter, as PCRE reads them
     * at offset 0 only; a comment after them goes on a line of its own, and
     * an empty first branch gets no line.
     */
    public function root(NodeInterface $pattern): string
    {
        $laidOut = $this->active;

        try {
            $body = $pattern->accept($this->printer);
        } finally {
            $this->active = false;
            $this->parentBroke = false;
        }

        if (!$laidOut) {
            return $body;
        }

        $options = StartOptions::of($body);
        $rest = substr($body, \strlen($options));
        if (str_starts_with($rest, $this->trail)) {
            $rest = substr($rest, \strlen($this->trail));
        }

        if ('' !== $rest && !str_starts_with($rest, "\n")) {
            $rest = "\n".self::INDENT.$rest;
        }

        return $options.$rest;
    }

    /**
     * @param list<NodeInterface> $alternatives
     */
    public function alternation(array $alternatives): string
    {
        $this->parentBroke = false;
        // "| " hangs in the indent, so every branch starts in one column.
        $bar = str_repeat(' ', max(0, $this->indentLevel * \strlen(self::INDENT) - 2)).'|';
        $result = $alternatives[0]->accept($this->printer);

        for ($i = 1, $count = \count($alternatives); $i < $count; $i++) {
            // Once x is off, the rest of the group stays on its line.
            if (!str_contains($this->printer->flags(), 'x')) {
                $result .= '|'.$alternatives[$i]->accept($this->printer);

                continue;
            }

            $previous = $alternatives[$i - 1];
            $separator = "\n".$bar;

            // A comment written after "|" trails the branch before it.
            $lead = $this->leadingSameLineComment($alternatives[$i], $previous->getEndPosition());
            $previousLast = $previous instanceof SequenceNode ? ($previous->children[\count($previous->children) - 1] ?? null) : $previous;
            if (null !== $lead && !$previousLast instanceof CommentNode
                && !str_contains(substr($result, (int) strrpos($result, "\n")), $this->trail)) {
                $this->skipNode = $lead;
                $result .= $this->trail.rtrim($lead->comment, "\n");
            }

            $first = $alternatives[$i] instanceof SequenceNode ? ($alternatives[$i]->children[0] ?? null) : $alternatives[$i];
            if (substr_count($this->gap($previous, $alternatives[$i]), "\n") >= 2 && $first !== $this->skipNode) {
                $separator = "\n".$separator;
            }

            $alternative = $alternatives[$i]->accept($this->printer);
            $this->skipNode = null;
            $result .= $separator.('' === $alternative ? '' : ' '.$alternative);
        }

        return $result;
    }

    /**
     * A broken sequence: each run of atoms on a line when it fits, broken
     * over lines when it does not (see runLines), each group on lines of its
     * own, each "#" comment where its author put it.
     */
    public function sequence(SequenceNode $node): string
    {
        $broke = $this->parentBroke;
        $this->parentBroke = false;
        if (!$broke) {
            $inline = $this->tryInline($node) ?? $this->inlineWithTrailingComment($node);
            if (null !== $inline) {
                return $inline;
            }
        }

        $lines = [];
        // The run of atoms being joined, on one line and piece by piece.
        $current = '';
        $pieces = [];
        $previous = null;
        $glued = false;
        foreach (self::flatChildren($node) as $child) {
            if ($child === $this->skipNode) {
                $this->skipNode = null;
                $previous = $child;

                continue;
            }

            // Once x is off, nothing may be split any more.
            if ($glued || !str_contains($this->printer->flags(), 'x')) {
                $glued = true;
                $this->joinRun($current, $pieces, $previous, $child, $this->forceInline($child));
                $previous = $child;

                continue;
            }

            $gap = null === $previous ? '' : $this->gap($previous, $child);
            $blank = null !== $previous && substr_count($gap, "\n") + ($previous instanceof CommentNode && str_ends_with($previous->comment, "\n") ? 1 : 0) >= 2;

            if ($child instanceof CommentNode && $child->isExtended) {
                $text = $child->accept($this->printer);
                $trailing = null !== $previous && !$previous instanceof CommentNode && !str_contains($gap, "\n");
                if ('' !== $current) {
                    array_push($lines, ...$this->runLines($pieces, $current, false));
                    $current = '';
                    $pieces = [];
                }

                if ($blank && [] !== $lines) {
                    $lines[] = '';
                }

                if ($trailing && [] !== $lines && '' !== $lines[\count($lines) - 1]) {
                    $lines[\count($lines) - 1] .= $this->trail.$text;
                } else {
                    $lines[] = $text;
                }

                $previous = $child;

                continue;
            }

            $isBlock = self::isBlock($child);
            if ($isBlock || $blank || ($previous instanceof CommentNode && '' === $current)) {
                if ('' !== $current) {
                    array_push($lines, ...$this->runLines($pieces, $current, false));
                    $current = '';
                    $pieces = [];
                }

                if ($blank && [] !== $lines) {
                    $lines[] = '';
                }

                if ($isBlock) {
                    $lines[] = $child->accept($this->printer);
                    $previous = $child;

                    continue;
                }
            }

            $this->joinRun($current, $pieces, $previous, $child, $this->forceInline($child));
            $previous = $child;
        }

        if ('' !== $current) {
            array_push($lines, ...$this->runLines($pieces, $current, $glued));
        }

        $indent = self::indent($this->indentLevel);
        $out = '';
        foreach ($lines as $i => $line) {
            $out .= 0 === $i ? $line : ('' === $line ? "\n" : "\n".$indent.$line);
        }

        return $out;
    }

    /**
     * A group that does not fit on one line: its opening on a line, its body
     * indented, its ")" and quantifier on a line of their own.
     *
     * @param string $flags the modifiers the group spells
     * @param string $inner the modifiers in force inside it
     */
    public function group(GroupNode $node, string $flags, string $inner): string
    {
        $inline = $this->tryInline($node);
        if (null !== $inline) {
            return $inline;
        }

        // Without x inside, a line break would be a literal.
        if (!str_contains($inner, 'x')) {
            return $this->forceInline($node);
        }

        $this->printer->countCapture($node);

        $opening = $this->printer->groupOpening($node, $flags);
        $level = $this->indentLevel;
        $outer = $this->printer->flags();
        $this->printer->setFlags($inner);

        // A comment written on the opening line trails it.
        $trailing = '';
        $lead = $this->leadingSameLineComment($node->child, $node->getStartPosition() + \strlen($opening));
        if (null !== $lead) {
            $this->skipNode = $lead;
            $trailing = $this->trail.rtrim($lead->comment, "\n");
        }

        $this->indentLevel++;
        $this->parentBroke = true;
        $child = $node->child->accept($this->printer);
        $this->parentBroke = false;
        $this->indentLevel--;
        $this->skipNode = null;
        $closing = str_contains($this->printer->flags(), 'x') ? "\n".self::indent($level).')' : ')';
        $this->printer->setFlags($outer);

        return $opening.$trailing.$this->bodyLine($child, $level + 1).$child.$closing;
    }

    public function conditional(ConditionalNode $node): string
    {
        // Without x, a line break would be a literal. "(?-x)" in the "yes"
        // branch carries into the "no" branch, which then stays on its line,
        // and ends with the conditional.
        $flags = $this->printer->flags();
        if (!str_contains($flags, 'x')) {
            // Not reached today: a sequence, an alternation and a group each
            // glue what follows a "(?-x)" before a conditional is laid out.
            // Kept so that a new caller cannot break a line where x is off.
            return $this->forceInline($node);
        }

        $inline = $this->tryInline($node);
        if (null !== $inline) {
            return $inline;
        }

        $this->active = false;
        $condition = $this->printer->conditionText($node);
        $this->active = true;

        $level = $this->indentLevel;
        $this->indentLevel++;
        $yes = $node->yes->accept($this->printer);
        $yesKeepsX = str_contains($this->printer->flags(), 'x');
        $hasNo = !($node->no instanceof LiteralNode && '' === $node->no->value);
        $no = $yesKeepsX ? $node->no->accept($this->printer) : $this->forceInline($node->no);
        $this->indentLevel--;
        $closesOnItsLine = str_contains($this->printer->flags(), 'x');
        $this->printer->setFlags($flags);

        $text = '(?'.$condition.$this->bodyLine($yes, $level + 1).$yes;
        if ('' !== $no || $hasNo) {
            $text .= $yesKeepsX
                ? "\n".str_repeat(' ', ($level + 1) * \strlen(self::INDENT) - 2).'|'.('' === $no ? '' : ' '.$no)
                : '|'.$no;
        }

        return $text.($closesOnItsLine ? "\n".self::indent($level) : '').')';
    }

    /**
     * "(?(DEFINE)...)", whose body breaks as a group's does. The printer
     * puts back the modifiers in force around it: a "(?-x)" in it ends with
     * it.
     */
    public function define(DefineNode $node): string
    {
        if (!str_contains($this->printer->flags(), 'x')) {
            return '(?(DEFINE)'.$this->forceInline($node->content).')';
        }

        $inline = $this->tryInline($node);
        if (null !== $inline) {
            return $inline;
        }

        $level = $this->indentLevel;
        $this->indentLevel++;
        $this->parentBroke = true;
        $content = $node->content->accept($this->printer);
        $this->parentBroke = false;
        $this->indentLevel--;

        // Once x is off, a line break would be a literal.
        $closing = str_contains($this->printer->flags(), 'x') ? "\n".self::indent($level).')' : ')';

        return '(?(DEFINE)'.$this->bodyLine($content, $level + 1).$content.$closing;
    }

    /**
     * A "\Q...\E" run standing alone, a whole branch, reads back as a
     * sequence of literals: it is laid out as one.
     */
    public function literal(LiteralNode $node): string
    {
        $text = $this->forceInline($node);
        $current = '';
        $pieces = [];
        $this->joinRun($current, $pieces, null, $node, $text);

        return implode("\n".self::indent($this->indentLevel), $this->runLines($pieces, $current, !str_contains($this->printer->flags(), 'x')));
    }

    /**
     * How many groups the deepest path through a node nests. Lookarounds,
     * conditionals, "(?|", atomic groups, DEFINE and a scope such as
     * "(?i:)", empty or not, count; a setting such as "(?i)" and a class do
     * not.
     */
    private static function groupDepth(NodeInterface $node): int
    {
        if ($node instanceof CharClassNode || $node instanceof ExtendedCharClassNode) {
            return 0;
        }

        $own = ($node instanceof GroupNode && !(self::isSetting($node) && self::isEmpty($node->child)))
            || $node instanceof ConditionalNode || $node instanceof DefineNode ? 1 : 0;

        $deepest = 0;
        foreach ($node->getChildren() as $child) {
            $deepest = max($deepest, self::groupDepth($child));
        }

        return $own + $deepest;
    }

    /**
     * Whether a node takes lines of its own once its parent breaks: a group
     * with a body, a conditional, DEFINE, quantified or not.
     */
    private static function isBlock(NodeInterface $node): bool
    {
        if ($node instanceof QuantifierNode) {
            return self::isBlock($node->node);
        }

        if ($node instanceof GroupNode) {
            return !self::isEmpty($node->child);
        }

        return $node instanceof ConditionalNode || $node instanceof DefineNode;
    }

    /**
     * The children of a sequence, those of a sequence nested in it in its
     * place: "\1234" reads as "\123" then "4", one sequence, and the output
     * reads back as two atoms of the sequence around it.
     *
     * @return list<NodeInterface>
     */
    private static function flatChildren(SequenceNode $node): array
    {
        $children = [];
        foreach ($node->children as $child) {
            if ($child instanceof SequenceNode) {
                array_push($children, ...self::flatChildren($child));
            } else {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * Whether an atom is white space, quantified or not: "\s", "\h", "\v",
     * "\R", or a literal space, tab, line feed, carriage return, vertical
     * tab or form feed, however it is spelled.
     */
    private static function isWhiteSpaceAtom(NodeInterface $node): bool
    {
        if ($node instanceof QuantifierNode) {
            $node = $node->node;
        }

        return match (true) {
            $node instanceof CharTypeNode => \in_array($node->value, ['s', 'h', 'v', 'R'], true),
            $node instanceof LiteralNode => !$node->isRaw && self::isWhiteSpaceCharacter($node->value),
            $node instanceof CharLiteralNode, $node instanceof ControlCharNode => $node->codePoint < 0x80 && self::isWhiteSpaceCharacter(\chr($node->codePoint)),
            default => false,
        };
    }

    /**
     * Pieces $from to $to on one line, each after its separator.
     *
     * @param list<array{string, int, string, bool}> $pieces
     */
    private static function joinPieces(array $pieces, int $from, int $to): string
    {
        $text = $pieces[$from][0];
        for ($i = $from + 1; $i <= $to; $i++) {
            $text .= $pieces[$i][2].$pieces[$i][0];
        }

        return $text;
    }

    /**
     * The lines of a text, whatever line break ends each.
     *
     * @return non-empty-list<string>
     */
    private static function physicalLines(string $text): array
    {
        return false === strpbrk($text, "\n\r") ? [$text] : explode("\n", strtr(str_replace("\r\n", "\n", $text), "\r", "\n"));
    }

    /**
     * Whether a line ends in an escaped space, "\ ", read escape by escape:
     * "\\ " is an escaped backslash, then a space, while "\c\\ " is the
     * control character "\c\", then an escaped space. The laid-out and the
     * compacted patterns hold no "\Q...\E" run but one holding a bracket of
     * the delimiter, which ends in "\E": the printer writes any other quoted
     * text with escapes.
     */
    private static function endsInEscapedSpace(string $line): bool
    {
        if (!str_ends_with($line, '\\ ')) {
            return false;
        }

        $last = \strlen($line) - 2;
        $i = 0;
        while ($i < $last) {
            // "\c" takes the character after it, a backslash included.
            $i += '\\' !== $line[$i] ? 1 : ('c' === $line[$i + 1] ? 3 : 2);
        }

        return $i === $last;
    }

    /**
     * A node on one line, when it fits from the current indent, holds no
     * "#" comment and nests at most two groups; past the indent cap, on one
     * line whatever its width. Null when it has to break.
     *
     * @phpstan-impure
     */
    private function tryInline(NodeInterface $node): ?string
    {
        $scope = $this->printer->scope();
        $sawLineComment = $this->sawLineComment;
        $this->sawLineComment = false;
        $text = $this->forceInline($node);

        if (!$this->sawLineComment && (self::MAX_INDENT <= $this->indentLevel
            || (false === strpbrk($text, "\n\r") && $this->fits($text) && self::isShallow($node)))) {
            $this->sawLineComment = $sawLineComment;

            return $text;
        }

        $this->printer->restoreScope($scope);
        $this->sawLineComment = $sawLineComment;

        return null;
    }

    /**
     * Whether a line holding $text fits the width from the current indent.
     * An escaped space ending it counts as "\x20", the spelling it takes at
     * the end of a line.
     */
    private function fits(string $text): bool
    {
        return \strlen(self::INDENT) * $this->indentLevel + $this->columns($text) + (self::endsInEscapedSpace($text) ? 2 : 0) <= $this->width;
    }

    /**
     * A node on one line, whatever its width.
     *
     * @phpstan-impure
     */
    private function forceInline(NodeInterface $node): string
    {
        $this->active = false;

        try {
            return $node->accept($this->printer);
        } finally {
            $this->active = true;
        }
    }

    /**
     * Items on one line, one after the other.
     *
     * @param non-empty-list<NodeInterface> $items
     *
     * @phpstan-impure
     */
    private function inlineRun(array $items): string
    {
        $this->active = false;

        try {
            $text = $items[0]->accept($this->printer);
            for ($i = 1, $count = \count($items); $i < $count; $i++) {
                $next = $items[$i]->accept($this->printer);
                $text .= $this->printer->separator($text, $items[$i - 1], $items[$i], $next).$next;
            }

            return $text;
        } finally {
            $this->active = true;
        }
    }

    /**
     * Join an atom to the run: to its text on one line, after what separates
     * it from the atom before, and to its pieces, the first of which carries
     * that separator, and whether no line break may stand before it.
     *
     * @param list<array{string, int, string, bool}> $pieces
     */
    private function joinRun(string &$current, array &$pieces, ?NodeInterface $previous, NodeInterface $child, string $text): void
    {
        $joined = '' !== $current && null !== $previous;
        $separator = $joined ? $this->printer->separator($current, $previous, $child, $text) : '';

        // A "(?:)" written to keep two atoms apart reads back as an atom of
        // its own: so it is laid out, and formatting the output again
        // changes nothing. Anything else that would extend a construct the
        // run ends with, "9" after "{2", stays on its line: read back across
        // a line break, the two would ask for a "(?:)" between them.
        $glued = $joined && '' !== $text && '(?:)' !== $separator && $this->printer->opensConstruct($current, $text[0]);
        $current .= $separator.$text;
        if ('(?:)' === $separator) {
            $pieces[] = [$separator, self::PIECE_UNIT, '', false];
            $separator = '';
        }

        foreach ($this->runPieces($child, $text) as $index => [$piece, $kind]) {
            $pieces[] = [$piece, $kind, 0 === $index ? $separator : '', 0 === $index && $glued];
        }
    }

    /**
     * What an atom of a run is to the layout, as the output writes it, each
     * piece with its kind (one of the PIECE_* constants): a literal written
     * as escapes, a "\Q...\E" run respelled, is one piece per character.
     *
     * @return list<array{string, int}>
     */
    private function runPieces(NodeInterface $node, string $text): array
    {
        if ('' === $text) {
            return [];
        }

        if ($node instanceof LimitMatchNode || ($node instanceof PcreVerbNode && $text === StartOptions::of($text))) {
            return [[$text, self::PIECE_OPTION]];
        }

        if (self::isWhiteSpaceAtom($node)) {
            return [[$text, self::PIECE_SPACE]];
        }

        if (($node instanceof AnchorNode && '^' === $node->value) || ($node instanceof AssertionNode && \in_array($node->value, ['A', 'G'], true))) {
            return [[$text, self::PIECE_START]];
        }

        if (($node instanceof AnchorNode && '$' === $node->value) || ($node instanceof AssertionNode && \in_array($node->value, ['z', 'Z'], true))) {
            return [[$text, self::PIECE_END]];
        }

        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode) {
            return [[$text, self::PIECE_WORD]];
        }

        $quoted = $node instanceof LiteralNode ? $this->printer->quotedRunPieces($node) : null;
        if (null !== $quoted) {
            return $quoted;
        }

        // A "\Q...\E" run kept as written is one unit, as is a raw literal.
        if (!$node instanceof LiteralNode || $node->isRaw || str_starts_with($text, '\\Q')) {
            return [[$text, self::PIECE_UNIT]];
        }

        $characters = $this->countsCodePoints ? mb_str_split($node->value, 1, 'UTF-8') : Bytes::split($node->value);
        if (1 === \count($characters)) {
            return [[$text, self::PIECE_WORD]];
        }

        $pieces = [];
        $written = '';
        $spaced = false;
        foreach ($characters as $character) {
            $piece = $this->printer->escapeString($character);
            $written .= $piece;
            $spaced = $spaced || self::isWhiteSpaceCharacter($character);
            $pieces[] = [$piece, self::isWhiteSpaceCharacter($character) ? self::PIECE_SPACE : self::PIECE_WORD];
        }

        // Written otherwise, "é" raw in byte mode, it still reads back as
        // literal characters: a piece of a word.
        if ($written === $text) {
            return $pieces;
        }

        return [[$text, $spaced ? self::PIECE_UNIT : self::PIECE_WORD]];
    }

    /**
     * A run of atoms on the lines it takes. A run that fits its line, or
     * that holds a part where x is off, stays on it. Otherwise the start
     * options it opens with keep a line of their own, which goes up to the
     * delimiter's, as do a leading "^", "\A" or "\G" and a trailing "$",
     * "\z" or "\Z"; the rest breaks after each white-space separator, one
     * field per line (see fieldLines).
     *
     * @param list<array{string, int, string, bool}> $pieces
     *
     * @return list<string>
     */
    private function runLines(array $pieces, string $text, bool $glued): array
    {
        if ($glued || $this->fitsFrom($this->indentColumns(), $text)) {
            return [$text];
        }

        $lines = [];
        $first = 0;
        $last = \count($pieces) - 1;
        while ($first <= $last && self::PIECE_OPTION === $pieces[$first][1]) {
            $first++;
        }

        if ($first > 0) {
            $lines[] = self::joinPieces($pieces, 0, $first - 1);
            $rest = $first <= $last ? self::joinPieces($pieces, $first, $last) : '';
            if ('' === $rest || $this->fitsFrom($this->indentColumns(), $rest)) {
                return '' === $rest ? $lines : [...$lines, $rest];
            }
        }

        if ($first < $last && self::PIECE_START === $pieces[$first][1]) {
            $lines[] = $pieces[$first][0];
            $first++;
        }

        $end = null;
        if ($first < $last && self::PIECE_END === $pieces[$last][1]) {
            $end = $pieces[$last][0];
            $last--;
        }

        // White space in a row is one separator: the field ends after it.
        $from = $first;
        for ($i = $first; $i <= $last; $i++) {
            if (self::PIECE_SPACE === $pieces[$i][1] && ($i === $last || self::PIECE_SPACE !== $pieces[$i + 1][1])) {
                array_push($lines, ...$this->fieldLines($pieces, $from, $i));
                $from = $i + 1;
            }
        }

        if ($from <= $last) {
            array_push($lines, ...$this->fieldLines($pieces, $from, $last));
        }

        if (null !== $end) {
            $lines[] = $end;
        }

        return $lines;
    }

    /**
     * A field of a run on one line when it fits, filled up to the width
     * otherwise: a line takes units while they fit and breaks only between
     * two of them. Unquantified literal characters in a row are one unit, a
     * word; any other atom is one. The white space ending the field goes
     * with the unit before it, past the width if need be. A unit wider than
     * the width has a line of its own. A line break between two units is
     * layout under x, where it parts them as the space a separator would
     * write does: "\1" ending a line and "0" opening the next stay a
     * reference and a digit. An atom that would extend a construct the
     * unit before ends with goes with that unit.
     *
     * @param list<array{string, int, string, bool}> $pieces
     *
     * @return list<string>
     */
    private function fieldLines(array $pieces, int $from, int $to): array
    {
        $text = self::joinPieces($pieces, $from, $to);
        if ($this->fitsFrom($this->indentColumns(), $text)) {
            return [$text];
        }

        // Each unit, and the separator written before it.
        $units = [];
        $separators = [];
        $inWord = false;
        for ($i = $from; $i <= $to; $i++) {
            [$piece, $kind, $separator, $glued] = $pieces[$i];
            $last = \count($units) - 1;
            if ($last >= 0 && ($glued || self::PIECE_SPACE === $kind || ($inWord && self::PIECE_WORD === $kind))) {
                $units[$last] .= $separator.$piece;
            } else {
                $units[] = $piece;
                $separators[] = $separator;
            }

            $inWord = self::PIECE_WORD === $kind;
        }

        $lines = [];
        $line = null;
        $column = 0;
        foreach ($units as $index => $unit) {
            $separator = $separators[$index];
            if (null !== $line) {
                if ($this->fitsFrom($column, $separator.$unit)) {
                    $line .= $separator.$unit;
                    $column = $this->columnAfter($column, $separator.$unit);

                    continue;
                }

                $lines[] = $line;
            }

            $line = $unit;
            $column = $this->columnAfter($this->indentColumns(), $unit);
        }

        if (null !== $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Whether text written from $column on fits the width, each line of it
     * when it holds line breaks (those a "(?#...)" holds, after which a line
     * starts with its indent). An escaped space ending it counts as "\x20",
     * the spelling it takes at the end of a line.
     */
    private function fitsFrom(int $column, string $text): bool
    {
        $lines = self::physicalLines($text);
        $last = \count($lines) - 1;
        foreach ($lines as $index => $line) {
            $end = (0 === $index ? $column : 0) + $this->columns($line) + ($index === $last && self::endsInEscapedSpace($line) ? 2 : 0);
            if ($end > $this->width) {
                return false;
            }
        }

        return true;
    }

    /**
     * The column text written from $column on ends at.
     */
    private function columnAfter(int $column, string $text): int
    {
        $lines = self::physicalLines($text);

        return 1 === \count($lines) ? $column + $this->columns($text) : $this->columns($lines[\count($lines) - 1]);
    }

    private function indentColumns(): int
    {
        return \strlen(self::INDENT) * $this->indentLevel;
    }

    private function columns(string $text): int
    {
        return self::columnsOf($text, $this->countsCodePoints);
    }

    /**
     * A sequence whose only comment closes it, on the line of what precedes
     * it: the rest stays on one line when it fits, the comment trailing it.
     */
    private function inlineWithTrailingComment(SequenceNode $node): ?string
    {
        $children = $node->children;
        $last = $children[\count($children) - 1] ?? null;
        if (!$last instanceof CommentNode || !$last->isExtended || \count($children) < 2 || $last === $this->skipNode) {
            return null;
        }

        $rest = array_values(array_filter(\array_slice($children, 0, -1), fn (NodeInterface $child): bool => $child !== $this->skipNode));
        if ([] === $rest || str_contains($this->gap($rest[\count($rest) - 1], $last), "\n")) {
            return null;
        }

        $shallow = true;
        foreach ($rest as $child) {
            if ($child instanceof CommentNode) {
                return null;
            }

            $shallow = $shallow && self::isShallow($child);
        }

        $scope = $this->printer->scope();
        $sawLineComment = $this->sawLineComment;
        $this->sawLineComment = false;
        $text = $this->inlineRun($rest);

        if (!$this->sawLineComment && false === strpbrk($text, "\n\r") && $this->fits($text) && $shallow) {
            $this->sawLineComment = true;

            return $text.$this->trail.rtrim($last->comment, "\n");
        }

        $this->printer->restoreScope($scope);
        $this->sawLineComment = $sawLineComment;

        return null;
    }

    /**
     * What goes between an opening and its body: nothing when the body is
     * empty or starts on a line of its own already (an empty first branch),
     * as an empty branch gets no line.
     */
    private function bodyLine(string $body, int $level): string
    {
        return '' === $body || str_starts_with($body, "\n") ? '' : "\n".self::indent($level);
    }

    /**
     * The source text between two nodes, or '' without a source.
     */
    private function gap(NodeInterface $left, NodeInterface $right): string
    {
        if (null === $this->source) {
            return '';
        }

        $start = $left->getEndPosition();
        $length = $right->getStartPosition() - $start;

        return $length > 0 ? substr($this->source, $start, $length) : '';
    }

    /**
     * The comment a node starts with, when the source writes it on the line
     * of what comes before: it trails that line in the output too.
     */
    private function leadingSameLineComment(NodeInterface $node, int $after): ?CommentNode
    {
        $first = $node;
        while ($first instanceof AlternationNode || $first instanceof SequenceNode) {
            $children = $first instanceof AlternationNode ? $first->alternatives : $first->children;
            $first = $children[0] ?? null;
        }

        if (!$first instanceof CommentNode || !$first->isExtended || $first === $node || null === $this->source) {
            return null;
        }

        $length = $first->getStartPosition() - $after;
        if ($length < 0 || str_contains(substr($this->source, $after, $length), "\n")) {
            return null;
        }

        return $first;
    }
}
