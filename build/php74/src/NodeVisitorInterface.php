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

namespace PHPRegex\Parser;

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Node\VersionConditionNode;

/**
 * Defines the contract for a visitor that traverses the regex Abstract Syntax Tree (AST).
 *
 * @template-covariant TReturn = null The return type of the visitor's methods (e.g., `string`
 *                                    for `PatternPrinter`, `void` for `Validator`);
 *                                    null for a visitor that only collects.
 */
interface NodeVisitorInterface
{
    /**
     * @return TReturn
     */
    public function visitRegex(RegexNode $node);

    /**
     * @return TReturn
     */
    public function visitAlternation(AlternationNode $node);

    /**
     * @return TReturn
     */
    public function visitSequence(SequenceNode $node);

    /**
     * @return TReturn
     */
    public function visitGroup(GroupNode $node);

    /**
     * @return TReturn
     */
    public function visitQuantifier(QuantifierNode $node);

    /**
     * @return TReturn
     */
    public function visitLiteral(LiteralNode $node);

    /**
     * @return TReturn
     */
    public function visitCharLiteral(CharLiteralNode $node);

    /**
     * @return TReturn
     */
    public function visitCharType(CharTypeNode $node);

    /**
     * @return TReturn
     */
    public function visitDot(DotNode $node);

    /**
     * @return TReturn
     */
    public function visitAnchor(AnchorNode $node);

    /**
     * @return TReturn
     */
    public function visitAssertion(AssertionNode $node);

    /**
     * @return TReturn
     */
    public function visitKeep(KeepNode $node);

    /**
     * @return TReturn
     */
    public function visitCharClass(CharClassNode $node);

    /**
     * @return TReturn
     */
    public function visitRange(RangeNode $node);

    /**
     * @return TReturn
     */
    public function visitBackref(BackrefNode $node);

    /**
     * @return TReturn
     */
    public function visitControlChar(ControlCharNode $node);

    /**
     * @return TReturn
     */
    public function visitScriptRun(ScriptRunNode $node);

    /**
     * @return TReturn
     */
    public function visitExtendedCharClass(ExtendedCharClassNode $node);

    /**
     * @return TReturn
     */
    public function visitClassSetOperation(ClassSetOperationNode $node);

    /**
     * @return TReturn
     */
    public function visitVersionCondition(VersionConditionNode $node);

    /**
     * @return TReturn
     */
    public function visitUnicodeProp(UnicodePropNode $node);

    /**
     * @return TReturn
     */
    public function visitPosixClass(PosixClassNode $node);

    /**
     * @return TReturn
     */
    public function visitComment(CommentNode $node);

    /**
     * @return TReturn
     */
    public function visitConditional(ConditionalNode $node);

    /**
     * @return TReturn
     */
    public function visitSubroutine(SubroutineNode $node);

    /**
     * @return TReturn
     */
    public function visitPcreVerb(PcreVerbNode $node);

    /**
     * @return TReturn
     */
    public function visitDefine(DefineNode $node);

    /**
     * @return TReturn
     */
    public function visitLimitMatch(LimitMatchNode $node);

    /**
     * @return TReturn
     */
    public function visitCallout(CalloutNode $node);
}
