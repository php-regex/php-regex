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

namespace PHPRegex\Redos;

use PHPRegex\Parser\AbstractNodeVisitor;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;

/**
 * The first step a match attempt tries, read from the tree: the call to the
 * whole pattern when nothing that consumes stands before it, which the
 * engine then enters again with no progress made. True when the node lets
 * the attempt through to what follows, false when it tries something else
 * first.
 *
 * @extends AbstractNodeVisitor<SubroutineNode|bool>
 *
 * @internal
 */
final class FirstTriedStep extends AbstractNodeVisitor
{
    /**
     * The verbs that set an option for the whole pattern: they stand at its
     * start and try nothing.
     */
    private const OPENING_OPTIONS = [
        'UTF' => true, 'UTF8' => true, 'UCP' => true,
        'CR' => true, 'LF' => true, 'CRLF' => true, 'ANYCRLF' => true, 'ANY' => true, 'NUL' => true,
        'BSR_ANYCRLF' => true, 'BSR_UNICODE' => true,
        'NOTEMPTY' => true, 'NOTEMPTY_ATSTART' => true,
        'NO_AUTO_POSSESS' => true, 'NO_DOTSTAR_ANCHOR' => true, 'NO_JIT' => true, 'NO_START_OPT' => true,
        'CASELESS_RESTRICT' => true, 'TURKISH_CASING' => true,
    ];

    #[\Override]
    public function visitRegex(RegexNode $node): SubroutineNode|bool
    {
        return $node->pattern->accept($this);
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): SubroutineNode|bool
    {
        return \in_array($node->reference, ['R', '0'], true) ? $node : false;
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): SubroutineNode|bool
    {
        foreach ($node->children as $child) {
            $step = $child->accept($this);
            if (true !== $step) {
                return $step;
            }
        }

        return true;
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): SubroutineNode|bool
    {
        return [] === $node->alternatives ? false : $node->alternatives[0]->accept($this);
    }

    #[\Override]
    public function visitGroup(GroupNode $node): SubroutineNode|bool
    {
        if (!\in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::InlineFlags, GroupType::Atomic, GroupType::BranchReset], true)) {
            return false;
        }

        return $node->child->accept($this);
    }

    /**
     * A repeat of nothing, or a lazy one that may match nothing, is skipped
     * on the first try; any other tries its body.
     */
    #[\Override]
    public function visitQuantifier(QuantifierNode $node): SubroutineNode|bool
    {
        $min = $node->bounds->min ?? 0;
        $max = $node->bounds->max ?? null;
        if (0 === $max || (0 === $min && QuantifierType::Lazy === $node->type)) {
            return true;
        }

        return $node->node->accept($this);
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): bool
    {
        return '' === $node->value;
    }

    #[\Override]
    public function visitComment(CommentNode $node): bool
    {
        return true;
    }

    #[\Override]
    public function visitDefine(DefineNode $node): bool
    {
        return true;
    }

    #[\Override]
    public function visitKeep(KeepNode $node): bool
    {
        return true;
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): bool
    {
        return isset(self::OPENING_OPTIONS[$node->verb]);
    }

    /**
     * Every other node tries something of its own first.
     */
    #[\Override]
    protected function defaultReturn(): bool
    {
        return false;
    }
}
