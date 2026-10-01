<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Parser;

use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\AnchorNode;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\CalloutNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\ClassSetOperationNode;
use PhpRegex\Parser\Node\CommentNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\ControlCharNode;
use PhpRegex\Parser\Node\DefineNode;
use PhpRegex\Parser\Node\DotNode;
use PhpRegex\Parser\Node\ExtendedCharClassNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\KeepNode;
use PhpRegex\Parser\Node\LimitMatchNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\PcreVerbNode;
use PhpRegex\Parser\Node\PosixClassNode;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\RangeNode;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Node\ScriptRunNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Node\SubroutineNode;
use PhpRegex\Parser\Node\UnicodePropNode;
use PhpRegex\Parser\Node\VersionConditionNode;

/**
 * Defines the contract for a visitor that traverses the regex Abstract Syntax Tree (AST).
 *
 * @template-covariant TReturn The return type of the visitor's methods (e.g., `string`
 *                             for `PatternPrinter`, `void` for `Validator`).
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
