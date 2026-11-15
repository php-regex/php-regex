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

namespace PHPRegex\Linter\Extraction;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Greater;
use PhpParser\Node\Expr\BinaryOp\GreaterOrEqual;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BinaryOp\Smaller;
use PhpParser\Node\Expr\BinaryOp\SmallerOrEqual;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;

/**
 * A condition of a syntax tree whose names are resolved, as ValueCondition
 * reads it: what it does not evaluate is unknown, and no closure written
 * in the condition is entered. BUDGET nodes of a condition are read at
 * most, the rest of it unknown: a chain of thousands of "&&" is read in
 * linear time, each of its operators guarding with what is near it.
 *
 * @internal
 */
final class PhpParserConditions
{
    /**
     * How many nodes of a condition are read.
     */
    private const BUDGET = 64;

    private const COMPARISONS = [
        Identical::class => '===',
        NotIdentical::class => '!==',
        Equal::class => '==',
        NotEqual::class => '!=',
        Smaller::class => '<',
        SmallerOrEqual::class => '<=',
        Greater::class => '>',
        GreaterOrEqual::class => '>=',
    ];

    public static function of(Expr $expr): ValueCondition
    {
        $budget = self::BUDGET;

        return self::read($expr, $budget);
    }

    /**
     * @param int $budget how many nodes are left to read
     */
    private static function read(Expr $expr, int &$budget): ValueCondition
    {
        if (--$budget < 0) {
            return ValueCondition::unknown();
        }

        // The operand nearest to what the condition guards, on the right,
        // is read first.
        if ($expr instanceof BooleanAnd || $expr instanceof LogicalAnd) {
            $right = self::read($expr->right, $budget);

            return ValueCondition::and(self::read($expr->left, $budget), $right);
        }

        if ($expr instanceof BooleanOr || $expr instanceof LogicalOr) {
            $right = self::read($expr->right, $budget);

            return ValueCondition::or(self::read($expr->left, $budget), $right);
        }

        if ($expr instanceof BooleanNot) {
            return ValueCondition::not(self::read($expr->expr, $budget));
        }

        $operator = self::COMPARISONS[$expr::class] ?? null;
        if (null !== $operator && $expr instanceof BinaryOp) {
            $left = self::read($expr->left, $budget);

            return ValueCondition::compare($operator, $left, self::read($expr->right, $budget));
        }

        $name = self::variableName($expr);
        if (null !== $name) {
            return ValueCondition::variable($name);
        }

        if ($expr instanceof ArrayDimFetch && null !== $expr->dim) {
            $name = self::variableName($expr->var);
            $offset = self::integer($expr->dim);

            return null === $name || null === $offset ? ValueCondition::unknown() : ValueCondition::byte($name, $offset);
        }

        if ($expr instanceof String_) {
            return ValueCondition::literal($expr->value);
        }

        $integer = self::integer($expr);
        if (null !== $integer) {
            return ValueCondition::literal($integer);
        }

        if ($expr instanceof ConstFetch) {
            return match ($expr->name->toLowerString()) {
                'true' => ValueCondition::literal(true),
                'false' => ValueCondition::literal(false),
                'null' => ValueCondition::literal(null),
                default => ValueCondition::unknown(),
            };
        }

        return $expr instanceof FuncCall ? self::call($expr, $budget) : ValueCondition::unknown();
    }

    /**
     * A call by its name, each argument passed by position.
     *
     * @param int $budget how many nodes are left to read
     */
    private static function call(FuncCall $call, int &$budget): ValueCondition
    {
        if (!$call->name instanceof Name) {
            return ValueCondition::unknown();
        }

        $arguments = [];
        foreach ($call->getRawArgs() as $arg) {
            if (!$arg instanceof Arg || null !== $arg->name || $arg->unpack || $arg->byRef) {
                return ValueCondition::unknown();
            }

            $arguments[] = self::read($arg->value, $budget);
        }

        return ValueCondition::call(AstValueCollector::functionNames($call->name), $arguments);
    }

    private static function variableName(Expr $expr): ?string
    {
        return $expr instanceof Variable && \is_string($expr->name) && 'this' !== $expr->name ? $expr->name : null;
    }

    /**
     * An integer written as a literal, under one minus sign or none.
     */
    private static function integer(Expr $expr): ?int
    {
        if ($expr instanceof LNumber) {
            return $expr->value;
        }

        return $expr instanceof UnaryMinus && $expr->expr instanceof LNumber ? -$expr->expr->value : null;
    }
}
