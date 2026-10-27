<?php

declare(strict_types=1);

namespace PHPRegex\Build\Rector;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Switch_;
use PHPStan\Type\NeverType;
use Rector\Rector\AbstractRector;
use Rector\RuleDoc\CodeSample\CodeSample;
use Rector\RuleDoc\RuleDefinition;

/**
 * Gives back to each switch the downgrade wrote from a match what the match
 * meant. A switch compares loosely, and PHP 7.4 holds 'a' == 0 and
 * '1' == '01' true: every case becomes a strict comparison of the subject,
 * read once. A match no arm takes throws UnhandledMatchError, where a
 * switch falls through: a switch without default gets one that throws it.
 * The break the downgrade writes after a call that never returns is
 * dropped.
 *
 * The source writes no switch (match only), so every switch of the twin is
 * one the downgrade wrote.
 */
final class StrictSwitchRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Make the switches written from match statements strict and exhaustive', [
            new CodeSample(
                "switch (\$x) {\n    case 'a':\n        return 1;\n}",
                "switch (true) {\n    case \$x === 'a':\n        return 1;\n    default:\n        throw new \\UnhandledMatchError('Unhandled match case '.\\var_export(\$x, true));\n}",
            ),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [Switch_::class];
    }

    /**
     * @param Switch_ $node
     *
     * @return Switch_|list<Node\Stmt>|null
     */
    public function refactor(Node $node)
    {
        if ($this->isStrict($node)) {
            return null;
        }

        $before = [];
        $subject = $node->cond;
        $matchesTrue = $subject instanceof ConstFetch && 'true' === strtolower($subject->name->toString());
        if (!$matchesTrue && !$this->isPure($subject)) {
            $variable = new Variable('matchSubject'.$node->getStartLine());
            $before[] = new Expression(new Assign($variable, $subject));
            $subject = $variable;
        }

        $hasDefault = false;
        foreach ($node->cases as $case) {
            if (null === $case->cond) {
                $hasDefault = true;
            } else {
                $case->cond = new Identical($matchesTrue ? new ConstFetch(new Name('true')) : clone $subject, $case->cond);
            }

            $case->stmts = $this->withoutBreakAfterNever($case->stmts);
        }

        if (!$hasDefault) {
            $node->cases[] = new Case_(null, [new Expression(new Throw_($this->unhandled($matchesTrue ? new ConstFetch(new Name('true')) : clone $subject)))]);
        }

        $node->cond = new ConstFetch(new Name('true'));

        return [] === $before ? $node : [...$before, $node];
    }

    private function isStrict(Switch_ $switch): bool
    {
        if (!$switch->cond instanceof ConstFetch || 'true' !== strtolower($switch->cond->name->toString())) {
            return false;
        }

        $hasDefault = false;
        foreach ($switch->cases as $case) {
            if (null === $case->cond) {
                $hasDefault = true;
            } elseif (!$case->cond instanceof Identical) {
                return false;
            }
        }

        return $hasDefault;
    }

    /**
     * Whether reading the subject again reads the same value with no effect.
     */
    private function isPure(Expr $expr): bool
    {
        if ($expr instanceof Variable || $expr instanceof ConstFetch || $expr instanceof ClassConstFetch || $expr instanceof Scalar) {
            return true;
        }

        if ($expr instanceof PropertyFetch) {
            return $this->isPure($expr->var);
        }

        if ($expr instanceof StaticPropertyFetch) {
            return $expr->class instanceof Name;
        }

        if ($expr instanceof ArrayDimFetch) {
            return null !== $expr->dim && $this->isPure($expr->var) && $this->isPure($expr->dim);
        }

        return false;
    }

    /**
     * @param array<Node\Stmt> $stmts
     *
     * @return array<Node\Stmt>
     */
    private function withoutBreakAfterNever(array $stmts): array
    {
        $count = \count($stmts);
        if ($count >= 2 && $stmts[$count - 1] instanceof Break_ && $stmts[$count - 2] instanceof Expression && $this->getType($stmts[$count - 2]->expr) instanceof NeverType) {
            array_pop($stmts);
        }

        return array_values($stmts);
    }

    /**
     * The error PHP 8 throws for a match no arm takes, with its message: the
     * class of an object, the value written out otherwise.
     */
    private function unhandled(Expr $subject): New_
    {
        $type = $this->getType($subject);
        if ($type->isObject()->yes()) {
            $message = new Concat(new String_('Unhandled match case of type '), new FuncCall(new FullyQualified('get_class'), [new Arg(clone $subject)]));
        } elseif ($type->isScalar()->yes() || $type->isNull()->yes()) {
            $message = new Concat(new String_('Unhandled match case '), new FuncCall(new FullyQualified('var_export'), [new Arg(clone $subject), new Arg(new ConstFetch(new Name('true')))]));
        } else {
            $message = new Concat(new String_('Unhandled match case of type '), new FuncCall(new FullyQualified('gettype'), [new Arg(clone $subject)]));
        }

        return new New_(new FullyQualified('UnhandledMatchError'), [new Arg($message)]);
    }
}
