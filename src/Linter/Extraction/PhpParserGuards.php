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

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Greater;
use PhpParser\Node\Expr\BinaryOp\GreaterOrEqual;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BinaryOp\Smaller;
use PhpParser\Node\Expr\BinaryOp\SmallerOrEqual;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PHPRegex\Linter\Config\TargetConstraint;

/**
 * The guards on the PHP version of a file the PHP parser read, as the
 * stretches they hold over: the branches of an "if", an "elseif" and an
 * "else", the rest of a block after "if (<guard>) { return; }" (or
 * "throw", "continue", "break", "exit" alone in the body), the branches of
 * a ternary, the arms of "match (true)".
 *
 * @internal
 */
final class PhpParserGuards
{
    private const COMPARISONS = [
        GreaterOrEqual::class => '>=',
        Greater::class => '>',
        SmallerOrEqual::class => '<=',
        Smaller::class => '<',
        Identical::class => '===',
        NotIdentical::class => '!==',
        Equal::class => '==',
        NotEqual::class => '!=',
    ];

    private readonly GuardRegions $regions;

    /**
     * Whether an unqualified name of a namespace is read as PHP's own: the
     * file declares nothing named so.
     */
    private bool $unqualifiedIsGlobal = true;

    private function __construct(private readonly bool $shadowed)
    {
        $this->regions = new GuardRegions();
    }

    /**
     * @param array<Node> $ast
     */
    public static function regions(array $ast, string $content): GuardRegions
    {
        $guards = new self(VersionGuard::shadowedIn($content));
        $guards->visitAll(array_values($ast));

        return $guards->regions;
    }

    /**
     * The nodes of a list, a block of statements reading its early exits.
     *
     * @param list<Node> $nodes
     */
    private function visitAll(array $nodes): void
    {
        $statements = array_values(array_filter($nodes, static fn (Node $node): bool => $node instanceof Stmt));
        if ([] !== $statements && \count($statements) === \count($nodes)) {
            $this->earlyExits($statements);
        }

        foreach ($nodes as $node) {
            $this->visit($node);
        }
    }

    private function visit(Node $node): void
    {
        if ($node instanceof Namespace_) {
            $outer = $this->unqualifiedIsGlobal;
            $this->unqualifiedIsGlobal = null === $node->name || !$this->shadowed;
            $this->visitAll(array_values($node->stmts));
            $this->unqualifiedIsGlobal = $outer;

            return;
        }

        if ($node instanceof If_) {
            $this->branches($node);
        } elseif ($node instanceof Ternary) {
            $guard = $this->guard($node->cond);
            if (null !== $guard) {
                if (null !== $node->if) {
                    $this->cover($node->if, $guard->holds);
                }
                $this->cover($node->else, $guard->fails);
            }
        } elseif ($node instanceof Match_ && $node->cond instanceof ConstFetch && 'true' === $node->cond->name->toLowerString()) {
            foreach ($node->arms as $arm) {
                $holds = null;
                foreach ($arm->conds ?? [] as $cond) {
                    $guard = $this->guard($cond);
                    if (null === $guard) {
                        $holds = null;

                        break;
                    }
                    $holds = $holds?->union($guard->holds) ?? $guard->holds;
                }
                if (null !== $holds) {
                    $this->cover($arm->body, $holds);
                }
            }
        }

        foreach ($node->getSubNodeNames() as $name) {
            $sub = $node->{$name};
            if ($sub instanceof Node) {
                $this->visit($sub);
            } elseif (\is_array($sub)) {
                $nodes = [];
                foreach ($sub as $item) {
                    if ($item instanceof Node) {
                        $nodes[] = $item;
                    }
                }
                $this->visitAll($nodes);
            }
        }
    }

    /**
     * The branches of an "if": each holds where its condition holds and
     * every condition before it fails, as far as those are guards; the
     * "else" where they all fail.
     */
    private function branches(If_ $if): void
    {
        $failed = null;
        foreach ([[$if->cond, $if->stmts], ...array_map(static fn (ElseIf_ $elseIf): array => [$elseIf->cond, $elseIf->stmts], $if->elseifs)] as [$cond, $stmts]) {
            $guard = $this->guard($cond);
            $holds = null === $guard ? $failed : ($failed?->intersect($guard->holds) ?? $guard->holds);
            if (null !== $holds) {
                $this->coverStatements($stmts, $holds);
            }

            $failed = null === $guard ? $failed : ($failed?->intersect($guard->fails) ?? $guard->fails);
        }

        if (null !== $if->else && null !== $failed) {
            $this->coverStatements($if->else->stmts, $failed);
        }
    }

    /**
     * After "if (<guard>) { return; }", the rest of the block runs where
     * the guard fails.
     *
     * @param list<Stmt> $statements
     */
    private function earlyExits(array $statements): void
    {
        $end = $statements[\count($statements) - 1]->getEndFilePos();
        foreach ($statements as $statement) {
            if (!$statement instanceof If_ || [] !== $statement->elseifs || null !== $statement->else || !self::leaves($statement->stmts)) {
                continue;
            }

            $guard = $this->guard($statement->cond);
            if (null !== $guard) {
                $this->regions->add($statement->getEndFilePos() + 1, $end, $guard->fails);
            }
        }
    }

    /**
     * Whether a body is one statement that leaves the block.
     *
     * @param array<Stmt> $stmts
     */
    private static function leaves(array $stmts): bool
    {
        if (1 !== \count($stmts)) {
            return false;
        }

        $statement = array_values($stmts)[0];

        return $statement instanceof Return_
            || $statement instanceof Continue_
            || $statement instanceof Break_
            || ($statement instanceof Expression && ($statement->expr instanceof Throw_ || $statement->expr instanceof Exit_));
    }

    /**
     * What a condition says of the PHP version, or null when it is no
     * guard the lint reads.
     */
    private function guard(Expr $expr): ?VersionGuard
    {
        if ($expr instanceof BooleanAnd || $expr instanceof LogicalAnd) {
            $left = $this->guard($expr->left);
            $right = $this->guard($expr->right);

            return null === $left || null === $right ? null : $left->and($right);
        }

        $operator = self::COMPARISONS[$expr::class] ?? null;
        if (null !== $operator && $expr instanceof BinaryOp) {
            if ($this->names($expr->left, 'PHP_VERSION_ID') && $expr->right instanceof Int_) {
                return VersionGuard::versionId($operator, $expr->right->value, true);
            }
            if ($this->names($expr->right, 'PHP_VERSION_ID') && $expr->left instanceof Int_) {
                return VersionGuard::versionId($operator, $expr->left->value, false);
            }

            return null;
        }

        if ($expr instanceof FuncCall && $expr->name instanceof Name && 'version_compare' === $expr->name->toLowerString() && $this->global($expr->name)) {
            $args = $expr->getRawArgs();
            if (3 === \count($args) && $args[0] instanceof Arg && $args[1] instanceof Arg && $args[2] instanceof Arg
                && null === $args[0]->name && null === $args[1]->name && null === $args[2]->name
                && $this->names($args[0]->value, 'PHP_VERSION') && $args[1]->value instanceof String_ && $args[2]->value instanceof String_) {
                return VersionGuard::versionCompare($args[1]->value->value, $args[2]->value->value);
            }
        }

        return null;
    }

    /**
     * Whether the expression is PHP's own constant of that name.
     */
    private function names(Expr $expr, string $constant): bool
    {
        return $expr instanceof ConstFetch && $constant === $expr->name->toString() && $this->global($expr->name);
    }

    /**
     * Whether the name is PHP's own: written with a leading backslash, or
     * in a file that declares nothing named so.
     */
    private function global(Name $name): bool
    {
        return $name->isFullyQualified() || ($name->isUnqualified() && $this->unqualifiedIsGlobal);
    }

    /**
     * @param array<Stmt> $stmts
     */
    private function coverStatements(array $stmts, TargetConstraint $constraint): void
    {
        if ([] === $stmts) {
            return;
        }

        $stmts = array_values($stmts);
        $this->regions->add($stmts[0]->getStartFilePos(), $stmts[\count($stmts) - 1]->getEndFilePos(), $constraint);
    }

    private function cover(Node $node, TargetConstraint $constraint): void
    {
        $this->regions->add($node->getStartFilePos(), $node->getEndFilePos(), $constraint);
    }
}
