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

use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Analysis\GroupIndex;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Node\VersionConditionNode;
use PHPRegex\Parser\Text\Bytes;

/**
 * What the items written after an end anchor can match, read generously:
 * each question is answered no only when every way through the items is
 * known to fail. An assertion, a lookaround or a verb that may hold lets
 * the match go on, and so do a reference, a call, an "(*ACCEPT)" and a
 * character set nothing here can read. A rule built on these answers then
 * stays silent rather than call impossible a pattern the engine matches.
 *
 * @internal
 */
final class EndOfLineTail
{
    /**
     * At the anchor, wherever it holds: before a newline or at the end.
     */
    private const HERE = 0;

    /**
     * At the anchor, before the subject's last newline: "\z" fails there.
     */
    private const BEFORE_THE_LAST_NEWLINE = 1;

    /**
     * At the end of the subject, after its last newline: "^", "\A", "\G"
     * and "\b" fail there, a newline being no word character.
     */
    private const AFTER_THE_LAST_NEWLINE = 2;

    /**
     * At the end of the subject, where "\z" holds, or "$" under the D
     * modifier: a lookahead reads nothing there.
     */
    private const AT_THE_END = 3;

    /**
     * Where a reference or a call reads its group: anywhere in the subject,
     * so every assertion may hold.
     */
    private const ANYWHERE = 4;

    /**
     * The ASCII word characters; under UTF or UCP a byte above ASCII may be
     * part of one too.
     */
    private const WORD = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz';

    /**
     * The characters a newline of any convention ends with.
     */
    private const NEWLINE_ENDS = "\n\r\0";

    private readonly ByteCharSet $newlineStarts;

    /**
     * Whether a reference or a call is being read: its group matched
     * elsewhere, where nothing is known of what stands around it.
     */
    private bool $elsewhere = false;

    /**
     * The groups a reference or a call is being read through, keyed by
     * object id: one inside the group it reads is read no further.
     *
     * @var array<int, true>
     */
    private array $reading = [];

    /**
     * @param string                                        $newline        the newline of the pattern's convention: one character, or
     *                                                                      the characters a newline can start with ("\r\n" under
     *                                                                      "(*ANYCRLF)")
     * @param (\Closure(NodeInterface, string): ?bool)|null $readsCharacter whether an atom whose set the analyzer cannot read reads
     *                                                                      the character; null when that is not known either
     * @param ByteCharSet|null                              $before         the characters the one read just before the anchor can be,
     *                                                                      from lastCharacters(); null when the anchor may stand where
     *                                                                      nothing is known of it
     */
    public function __construct(
        private readonly CharSetAnalyzer $analyzer,
        private readonly string $newline,
        private readonly GroupIndex $groups,
        private readonly ?\Closure $readsCharacter = null,
        private readonly ?ByteCharSet $before = null,
    ) {
        $this->newlineStarts = self::setOf($newline);
    }

    /**
     * The characters the last one the items read can be, read back past
     * the items that read none; an empty set when none reads one, null
     * when that cannot be told: an item that may read nothing, a reference,
     * an alternation.
     *
     * @param array<int, NodeInterface> $nodes
     */
    public static function lastCharacters(array $nodes, CharSetAnalyzer $analyzer): ?ByteCharSet
    {
        foreach (array_reverse($nodes) as $node) {
            if (self::isZeroWidth($node) || ($node instanceof LiteralNode && '' === $node->value)
                || ($node instanceof GroupNode && !NodePredicates::isTransparentGroup($node->type))
            ) {
                continue;
            }

            if ($node instanceof GroupNode) {
                $set = self::lastCharacters([$node->child], $analyzer);
                if (null === $set || !$set->isEmpty()) {
                    return $set;
                }

                continue;
            }

            if ($node instanceof SequenceNode) {
                $set = self::lastCharacters($node->children, $analyzer);
                if (null === $set || !$set->isEmpty()) {
                    return $set;
                }

                continue;
            }

            if ($node instanceof QuantifierNode) {
                [$min] = QuantifierMath::parseRange($node->quantifier);

                return 0 === $min ? null : self::lastCharacters([$node->node], $analyzer);
            }

            if ($node instanceof DotNode) {
                return ByteCharSet::full();
            }

            if ($node instanceof LiteralNode || (self::readsOneCharacter($node) && !($node instanceof CharTypeNode && \in_array($node->value, ['R', 'X', 'C'], true)))) {
                $set = $analyzer->lastChars($node);

                return $set->isUnknown() ? null : $set;
            }

            return null;
        }

        return ByteCharSet::empty();
    }

    /**
     * Whether the items may match nothing at the anchor, at the end of the
     * subject when $atTheEnd says the anchor holds there only: a reference
     * to a group that captured nothing, a call of an empty group, or an
     * "(*ACCEPT)" that ends the match before the items that read.
     *
     * @param array<int, NodeInterface> $nodes
     */
    public function canMatchNothingHere(array $nodes, bool $atTheEnd): bool
    {
        return $this->allMayBeEmpty($nodes, $atTheEnd ? self::AT_THE_END : self::HERE, false);
    }

    /**
     * Whether the items may match nothing at the end of the subject, after
     * the last newline a "$" or "\Z" before them let through.
     *
     * @param array<int, NodeInterface> $nodes
     */
    public function canMatchNothingAtTheEnd(array $nodes): bool
    {
        return $this->allMayBeEmpty($nodes, self::AFTER_THE_LAST_NEWLINE, false);
    }

    /**
     * Whether the items may match a string that starts with a newline, or
     * nothing: the continuation a multiline "$" allows before any line end
     * or at the end. Every item before the one that reads the newline may
     * match nothing.
     *
     * @param array<int, NodeInterface> $nodes
     */
    public function canStartWithNewline(array $nodes, bool $dotAll): bool
    {
        return $this->sequenceMayStartWithNewline($nodes, $dotAll, true);
    }

    /**
     * Whether the items as a whole may match exactly the newline: the
     * continuation "$" without the m modifier and "\Z" allow before the
     * subject's last newline. The items before the one that reads it may
     * match nothing there, and the items after it at the end.
     *
     * @param array<int, NodeInterface> $nodes
     */
    public function canMatchTheLastNewline(array $nodes, bool $dotAll): bool
    {
        // The two sides are read once each, so that a tail of thousands of
        // items stays linear.
        $nothingAfter = [];
        $ok = true;
        for ($i = \count($nodes) - 1; $i >= 0; $i--) {
            $nothingAfter[$i] = $ok;
            $ok = $ok && $this->mayBeEmpty($nodes[$i], self::AFTER_THE_LAST_NEWLINE, $dotAll);
        }

        $nothingBefore = true;
        foreach ($nodes as $index => $node) {
            if (self::holdsAccept($node)) {
                return $nothingBefore;
            }

            if (NodePredicates::isStandaloneInlineFlagsGroup($node)) {
                $dotAll = self::dotAllAfter($node, $dotAll);

                continue;
            }

            if ($nothingBefore && $nothingAfter[$index] && $this->mayBeTheNewline($node, $dotAll)) {
                return true;
            }

            $nothingBefore = $nothingBefore && $this->mayBeEmpty($node, self::BEFORE_THE_LAST_NEWLINE, $dotAll);
        }

        return false;
    }

    /**
     * @param array<int, NodeInterface> $nodes
     */
    private function allMayBeEmpty(array $nodes, int $where, bool $dotAll): bool
    {
        foreach ($nodes as $node) {
            if (self::holdsAccept($node)) {
                return true;
            }

            if (!$this->mayBeEmpty($node, $where, $dotAll)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the node may match the empty string where $where says.
     */
    private function mayBeEmpty(NodeInterface $node, int $where, bool $dotAll): bool
    {
        if ($this->elsewhere) {
            $where = self::ANYWHERE;
        }

        if ($node instanceof LiteralNode) {
            return '' === $node->value;
        }

        if ($node instanceof DotNode || self::readsOneCharacter($node)) {
            return false;
        }

        if ($node instanceof AnchorNode) {
            return '^' !== $node->value || $this->circumflexMayHold($where);
        }

        if ($node instanceof AssertionNode) {
            return match ($node->value) {
                'z' => self::BEFORE_THE_LAST_NEWLINE !== $where,
                'A', 'G' => $this->nothingReadBefore($where),
                'b' => $this->boundaryMayHold($where, true),
                'B' => $this->boundaryMayHold($where, false),
                default => true,
            };
        }

        if ($node instanceof PcreVerbNode) {
            return !NodePredicates::alwaysFails($node);
        }

        if ($node instanceof QuantifierNode) {
            [$min] = QuantifierMath::parseRange($node->quantifier);

            return 0 === $min || $this->mayBeEmpty($node->node, $where, $dotAll);
        }

        if ($node instanceof GroupNode) {
            return $this->groupMayBeEmpty($node, $where, $dotAll);
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alternative) {
                if ($this->mayBeEmpty($alternative, $where, $dotAll)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof SequenceNode) {
            return $this->allMayBeEmpty($node->children, $where, $dotAll);
        }

        if ($node instanceof ConditionalNode) {
            return $this->mayBeEmpty($node->yes, $where, $dotAll) || $this->mayBeEmpty($node->no, $where, $dotAll);
        }

        if ($node instanceof ScriptRunNode) {
            return null === $node->content || $this->mayBeEmpty($node->content, $where, $dotAll);
        }

        if ($node instanceof BackrefNode || $node instanceof SubroutineNode) {
            return $this->anyGroupRead($node, fn (GroupNode $group): bool => $this->mayBeEmpty($group->child, self::HERE, true));
        }

        // A keep, a comment, a callout or a DEFINE matches nothing and holds;
        // a node read nowhere else may.
        return true;
    }

    /**
     * "\A" and "\G" hold only where nothing of the match was read: never
     * after the last newline, nor after a character read before the anchor.
     */
    private function nothingReadBefore(int $where): bool
    {
        return self::ANYWHERE === $where || (self::AFTER_THE_LAST_NEWLINE !== $where && null === $this->before);
    }

    /**
     * "^" holds at the start, or after a newline under the m modifier, which
     * this does not follow; never after the subject's last newline.
     */
    private function circumflexMayHold(int $where): bool
    {
        return match ($where) {
            self::ANYWHERE => true,
            self::AFTER_THE_LAST_NEWLINE => false,
            self::AT_THE_END => null === $this->before,
            default => null === $this->before || $this->before->intersects(self::setOf(self::NEWLINE_ENDS)),
        };
    }

    /**
     * "\b" holds between a word character and another kind, "\B" between
     * two of a kind. After the anchor stands a newline or the end, neither
     * a word character, so the character before decides.
     */
    private function boundaryMayHold(int $where, bool $boundary): bool
    {
        if (self::ANYWHERE === $where) {
            return true;
        }

        if (self::AFTER_THE_LAST_NEWLINE === $where) {
            return !$boundary;
        }

        if (null === $this->before) {
            return true;
        }

        $word = self::setOf(self::WORD);

        return $this->before->intersects($boundary ? $word->union(ByteCharSet::fromRange(0x80, 0xFF)) : $word->complement());
    }

    private static function setOf(string $characters): ByteCharSet
    {
        $set = ByteCharSet::empty();
        foreach (Bytes::split($characters) as $character) {
            $set = $set->union(ByteCharSet::fromChar($character));
        }

        return $set;
    }

    /**
     * A lookahead before a newline may hold when what it reads may start
     * with one, or may be empty; at the end of the subject only when it may
     * be empty. A lookbehind there may hold when what it reads may end with
     * the newline, or may be empty; before the newline, as a negative
     * lookaround that is not empty, it may hold.
     */
    private function groupMayBeEmpty(GroupNode $group, int $where, bool $dotAll): bool
    {
        if (NodePredicates::isTransparentGroup($group->type)) {
            return $this->mayBeEmpty($group->child, $where, self::dotAllInside($group, $dotAll));
        }

        if (NodePredicates::alwaysFails($group)) {
            return false;
        }

        if (self::ANYWHERE === $where) {
            return true;
        }

        if (GroupType::LookaheadPositive === $group->type) {
            return $this->mayBeEmpty($group->child, $where, $dotAll)
                || (self::AFTER_THE_LAST_NEWLINE !== $where && self::AT_THE_END !== $where && $this->mayStartWithNewline($group->child, $dotAll));
        }

        if (GroupType::LookbehindPositive === $group->type && self::AFTER_THE_LAST_NEWLINE === $where) {
            return $this->mayBeEmpty($group->child, self::HERE, true) || $this->mayEndWithNewline($group->child);
        }

        return true;
    }

    /**
     * Whether the node may match a string that starts with a newline.
     */
    private function mayStartWithNewline(NodeInterface $node, bool $dotAll): bool
    {
        return $this->edgeMayBeNewline($node, $dotAll, true);
    }

    /**
     * Whether the node may match a string that ends with a newline. The
     * inline flags are not followed backwards: a dot may read one.
     */
    private function mayEndWithNewline(NodeInterface $node): bool
    {
        return $this->edgeMayBeNewline($node, true, false);
    }

    /**
     * Whether the node may match a string whose first character, or last
     * one, is a newline.
     */
    private function edgeMayBeNewline(NodeInterface $node, bool $dotAll, bool $first): bool
    {
        if ($node instanceof LiteralNode) {
            return '' !== $node->value && $this->newlineStarts->intersects(ByteCharSet::fromChar($first ? $node->value[0] : $node->value[-1]));
        }

        if ($node instanceof DotNode) {
            return $dotAll;
        }

        if (self::readsOneCharacter($node)) {
            return $this->atomReads($node);
        }

        if ($node instanceof QuantifierNode) {
            [, $max] = QuantifierMath::parseRange($node->quantifier);

            return 0 !== $max && $this->edgeMayBeNewline($node->node, $dotAll, $first);
        }

        if ($node instanceof GroupNode) {
            return NodePredicates::isTransparentGroup($node->type)
                && $this->edgeMayBeNewline($node->child, self::dotAllInside($node, $dotAll), $first);
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alternative) {
                if ($this->edgeMayBeNewline($alternative, $dotAll, $first)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof SequenceNode) {
            return $first
                ? $this->sequenceMayStartWithNewline($node->children, $dotAll, false)
                : $this->sequenceMayEndWithNewline($node->children);
        }

        if ($node instanceof ConditionalNode) {
            return $this->edgeMayBeNewline($node->yes, $dotAll, $first) || $this->edgeMayBeNewline($node->no, $dotAll, $first);
        }

        if ($node instanceof ScriptRunNode) {
            return null !== $node->content && $this->edgeMayBeNewline($node->content, $dotAll, $first);
        }

        if ($node instanceof BackrefNode || $node instanceof SubroutineNode) {
            return $this->anyGroupRead($node, fn (GroupNode $group): bool => $this->edgeMayBeNewline($group->child, true, $first));
        }

        return !self::isZeroWidth($node);
    }

    /**
     * Whether the items may match a string that starts with a newline, or
     * nothing when $orNothing says so; an "(*ACCEPT)" ends the match where
     * it stands.
     *
     * @param array<int, NodeInterface> $nodes
     */
    private function sequenceMayStartWithNewline(array $nodes, bool $dotAll, bool $orNothing): bool
    {
        foreach ($nodes as $node) {
            if (NodePredicates::isStandaloneInlineFlagsGroup($node)) {
                $dotAll = self::dotAllAfter($node, $dotAll);

                continue;
            }

            if (self::holdsAccept($node) || $this->mayStartWithNewline($node, $dotAll)) {
                return true;
            }

            if (!$this->mayBeEmpty($node, self::HERE, $dotAll)) {
                return false;
            }
        }

        return $orNothing;
    }

    /**
     * Whether the items may match a string that ends with a newline, read
     * from the last one back.
     *
     * @param array<int, NodeInterface> $nodes
     */
    private function sequenceMayEndWithNewline(array $nodes): bool
    {
        foreach (array_reverse($nodes) as $node) {
            if (self::holdsAccept($node) || $this->mayEndWithNewline($node)) {
                return true;
            }

            if (!$this->mayBeEmpty($node, self::HERE, true)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Whether the node may match exactly the newline.
     */
    private function mayBeTheNewline(NodeInterface $node, bool $dotAll): bool
    {
        if ($node instanceof LiteralNode) {
            return $this->newline === $node->value;
        }

        if ($node instanceof DotNode) {
            return $dotAll;
        }

        if (self::readsOneCharacter($node)) {
            return 1 === \strlen($this->newline) && $this->atomReads($node);
        }

        if ($node instanceof QuantifierNode) {
            [$min, $max] = QuantifierMath::parseRange($node->quantifier);

            return 0 !== $max
                && $this->mayBeTheNewline($node->node, $dotAll)
                && ($min <= 1
                    || $this->mayBeEmpty($node->node, self::BEFORE_THE_LAST_NEWLINE, $dotAll)
                    || $this->mayBeEmpty($node->node, self::AFTER_THE_LAST_NEWLINE, $dotAll));
        }

        if ($node instanceof GroupNode) {
            return NodePredicates::isTransparentGroup($node->type)
                && $this->mayBeTheNewline($node->child, self::dotAllInside($node, $dotAll));
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alternative) {
                if ($this->mayBeTheNewline($alternative, $dotAll)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof SequenceNode) {
            return $this->canMatchTheLastNewline($node->children, $dotAll);
        }

        if ($node instanceof ConditionalNode) {
            return $this->mayBeTheNewline($node->yes, $dotAll) || $this->mayBeTheNewline($node->no, $dotAll);
        }

        if ($node instanceof ScriptRunNode) {
            return null !== $node->content && $this->mayBeTheNewline($node->content, $dotAll);
        }

        if ($node instanceof BackrefNode || $node instanceof SubroutineNode) {
            return $this->anyGroupRead($node, fn (GroupNode $group): bool => $this->mayBeTheNewline($group->child, true));
        }

        return !self::isZeroWidth($node);
    }

    /**
     * Whether the atom may read a newline character: from the set the
     * analyzer reads, else as the caller's question answers, else yes.
     */
    private function atomReads(NodeInterface $atom): bool
    {
        $set = $this->analyzer->firstChars($atom);
        if (!$set->isUnknown()) {
            return $set->intersects($this->newlineStarts);
        }

        if (null === $this->readsCharacter) {
            return true;
        }

        foreach (Bytes::split($this->newline) as $character) {
            if (false !== ($this->readsCharacter)($atom, $character)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether some group the reference may name, or the call may run,
     * passes the test; yes when the index tells no group, or when the
     * group is one being read through already.
     *
     * @param \Closure(GroupNode): bool $test
     */
    private function anyGroupRead(BackrefNode|SubroutineNode $node, \Closure $test): bool
    {
        $groups = $node instanceof BackrefNode ? $this->groups->groupsReferencedBy($node) : $this->groups->groupsCalledBy($node);
        if ([] === $groups) {
            return true;
        }

        foreach ($groups as $group) {
            $id = spl_object_id($group);
            if (isset($this->reading[$id])) {
                return true;
            }

            $this->reading[$id] = true;
            $elsewhere = $this->elsewhere;
            $this->elsewhere = true;

            try {
                if ($test($group)) {
                    return true;
                }
            } finally {
                unset($this->reading[$id]);
                $this->elsewhere = $elsewhere;
            }
        }

        return false;
    }

    /**
     * An atom of one character whose set the analyzer may read.
     */
    private static function readsOneCharacter(NodeInterface $node): bool
    {
        return $node instanceof CharLiteralNode
            || $node instanceof ControlCharNode
            || $node instanceof CharClassNode
            || $node instanceof CharTypeNode
            || $node instanceof ExtendedCharClassNode
            || $node instanceof UnicodePropNode
            || $node instanceof PosixClassNode;
    }

    /**
     * Whether the node reads no character wherever it stands.
     */
    private static function isZeroWidth(NodeInterface $node): bool
    {
        return $node instanceof AnchorNode
            || $node instanceof AssertionNode
            || $node instanceof KeepNode
            || $node instanceof CommentNode
            || $node instanceof CalloutNode
            || $node instanceof PcreVerbNode
            || $node instanceof LimitMatchNode
            || $node instanceof DefineNode
            || $node instanceof VersionConditionNode;
    }

    /**
     * Whether an "(*ACCEPT)" stands anywhere under the node: the match may
     * end there, whatever is written after it.
     */
    private static function holdsAccept(NodeInterface $node): bool
    {
        if ($node instanceof PcreVerbNode) {
            return 'ACCEPT' === explode(':', $node->verb, 2)[0];
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsAccept($child)) {
                return true;
            }
        }

        return false;
    }

    private static function dotAllAfter(GroupNode $flags, bool $dotAll): bool
    {
        return str_contains(NodePredicates::applyInlineFlags($dotAll ? 's' : '', (string) $flags->flags), 's');
    }

    private static function dotAllInside(GroupNode $group, bool $dotAll): bool
    {
        return GroupType::InlineFlags === $group->type && null !== $group->flags ? self::dotAllAfter($group, $dotAll) : $dotAll;
    }
}
