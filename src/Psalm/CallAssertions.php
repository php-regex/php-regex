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

namespace PHPRegex\Psalm;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Greater;
use PhpParser\Node\Expr\BinaryOp\GreaterOrEqual;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BinaryOp\Smaller;
use PhpParser\Node\Expr\BinaryOp\SmallerOrEqual;
use PhpParser\Node\Expr\BinaryOp\Spaceship;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use Psalm\StatementsSource;
use Psalm\Storage\Assertion\InArray;
use Psalm\Storage\Assertion\IsType;
use Psalm\Storage\Possibilities;
use Psalm\Type\Atomic;
use Psalm\Type\Union;

/**
 * What holds on a preg_* call's result: the type of $matches or of the
 * subject where the call returned 1, [] where it did not, and nothing
 * through a comparison, which Psalm would read as if the call returned a
 * boolean, nor where Psalm would read it of another value than the call
 * left: on a later pass over a loop body, or beside a right operand that
 * writes the variable again.
 *
 * @internal
 */
final class CallAssertions
{
    /**
     * The comparisons Psalm reads a call's assertions through.
     */
    public const COMPARISONS = [
        Identical::class,
        NotIdentical::class,
        Equal::class,
        NotEqual::class,
        Smaller::class,
        SmallerOrEqual::class,
        Greater::class,
        GreaterOrEqual::class,
        Spaceship::class,
    ];

    /**
     * The calls the plugin set assertions on, each with the arguments they
     * speak of; null until it sets some.
     *
     * @var \WeakMap<FuncCall, list<Expr>>|null
     */
    private static ?\WeakMap $asserted = null;

    /**
     * The assertion that the argument holds the type, or null when the
     * argument cannot be found among the call's.
     */
    public static function assertion(FuncCall $call, Expr $argument, Atomic|Union $type): ?Possibilities
    {
        // An assertion names the argument by its index in the call.
        $index = array_search($argument, array_map(static fn ($argument): Expr => $argument->value, $call->getArgs()), true);

        // A union of literals is asserted as in_array() asserts it.
        $assertion = $type instanceof Union ? new InArray($type) : new IsType($type);

        return \is_int($index) ? new Possibilities($index, [$assertion]) : null; // Never null: the argument is the call's.
    }

    /**
     * Where the call returned 1, $matches is the shape, or the subject is
     * narrowed; where it returned 0 or false, $matches is []. Psalm reads
     * these where the call is the condition or its negation, and where it is
     * compared with a boolean, which dropAssertions() undoes. Where the call
     * is false, Psalm also reads the negation of what holds where it is
     * true: "not a non-empty-string" leaves a string as it is.
     *
     * @param list<Possibilities|null> $ifTrue
     * @param list<Possibilities|null> $ifFalse
     */
    public static function assertOnResult(StatementsSource $source, FuncCall $call, array $ifTrue, array $ifFalse): void
    {
        $ifTrue = array_values(array_filter($ifTrue));
        $ifFalse = [] === $ifFalse ? null : array_values(array_filter($ifFalse));
        if (PsalmInternals::assert($source, $call, $ifTrue, $ifFalse)) {
            self::remember($call, [...$ifTrue, ...$ifFalse ?? []]);
        }
    }

    /**
     * As assertOnResult(), for a preg_match() call made inside the class
     * $self names: Psalm 7.11 and later read its condition from their cache
     * of assertions alone, where ConditionAssertions writes them too.
     *
     * @param list<Possibilities|null> $ifTrue
     * @param list<Possibilities|null> $ifFalse
     */
    public static function assertOnMatch(StatementsSource $source, ?string $self, FuncCall $call, array $ifTrue, array $ifFalse): void
    {
        $ifTrue = array_values(array_filter($ifTrue));
        $ifFalse = [] === $ifFalse ? null : array_values(array_filter($ifFalse));
        if (PsalmInternals::assert($source, $call, $ifTrue, $ifFalse)) {
            self::remember($call, [...$ifTrue, ...$ifFalse ?? []]);
            ConditionAssertions::cache($source, $self, $call, $ifTrue, $ifFalse);
        }
    }

    /**
     * Psalm reads a loop body more than once, and clears between two passes
     * the cache it reads a condition from, never the assertions set on a
     * call: what the plugin set on the call goes, before the call is read
     * again, as on a pass where its pattern is no longer one constant.
     */
    public static function forget(StatementsSource $source, FuncCall $call): void
    {
        if (null !== self::$asserted && isset(self::$asserted[$call])) {
            unset(self::$asserted[$call]);
            PsalmInternals::assert($source, $call, [], []);
            PsalmInternals::cacheAssertions($source, $call, null);
        }
    }

    /**
     * Psalm reads what the left operand of a && or a || asserts of a
     * variable the right one writes as if it spoke of the value the right
     * one leaves: where the left one is false, the right one never ran. A
     * call of the left operand whose assertions speak of a variable the
     * right one writes keeps none: the variable keeps Psalm's type there.
     * The right one writes the target of an assignment or of an increment,
     * and the $matches or $count of a preg_* call.
     */
    public static function dropOverwritten(BinaryOp $condition, StatementsSource $source, ?string $self): void
    {
        $asserted = self::$asserted;
        $written = null === $asserted ? [] : self::writtenIds($condition->right, $source, $self);
        if (null === $asserted || [] === $written) {
            return;
        }

        foreach ((new NodeFinder())->findInstanceOf($condition->left, FuncCall::class) as $call) {
            foreach ($asserted[$call] ?? [] as $argument) {
                $id = PsalmInternals::variableId($argument, $self, $source);
                if (null !== $id && self::overlaps($id, $written)) {
                    self::forget($source, $call);

                    break;
                }
            }
        }
    }

    /**
     * Psalm reads a call's assertions through a comparison as if the call
     * returned a boolean: "preg_match(…) !== false" would type $matches as
     * the shape where no match left [] (0 !== false). Where a preg_match()
     * call is an operand, its assertions go: Psalm analyses the comparison
     * before it reads them, and so does what the cache of assertions holds
     * for the call, which Psalm reads through a loose comparison with a
     * boolean. The stub asserts nothing on the call.
     */
    public static function dropAssertions(BinaryOp $comparison, StatementsSource $source): void
    {
        foreach ([$comparison->left, $comparison->right] as $operand) {
            if ($operand instanceof FuncCall && $operand->name instanceof Name && \in_array(CallArguments::globalFunction($operand->name, $source), ['preg_match', 'preg_match_all'], true)) {
                PsalmInternals::assert($source, $operand, [], []);
                PsalmInternals::cacheAssertions($source, $operand, null);
            }
        }
    }

    /**
     * @param list<Possibilities> $possibilities
     */
    private static function remember(FuncCall $call, array $possibilities): void
    {
        $arguments = $call->getArgs();
        $spoken = [];
        foreach ($possibilities as $possibility) {
            $index = $possibility->var_id;
            if (\is_int($index) && isset($arguments[$index])) {
                $spoken[] = $arguments[$index]->value;
            }
        }

        $asserted = self::$asserted ??= new \WeakMap();
        $asserted[$call] = $spoken;
    }

    /**
     * The ids of what the expression writes, as Psalm names them.
     *
     * @return list<string>
     */
    private static function writtenIds(Expr $expr, StatementsSource $source, ?string $self): array
    {
        $targets = [];
        foreach ((new NodeFinder())->findInstanceOf($expr, Expr::class) as $node) {
            if ($node instanceof Assign || $node instanceof AssignRef || $node instanceof AssignOp || $node instanceof PreInc || $node instanceof PreDec || $node instanceof PostInc || $node instanceof PostDec) {
                $targets[] = $node->var;
            } elseif ($node instanceof FuncCall && $node->name instanceof Name && !$node->isFirstClassCallable()) {
                $function = CallArguments::globalFunction($node->name, $source);
                $arguments = null === $function ? null : CallArguments::bind(array_values($node->getArgs()), CallArguments::PARAMETERS[$function]);
                array_push($targets, ...array_values(array_intersect_key($arguments ?? [], ['matches' => true, 'count' => true])));
            }
        }

        $ids = [];
        while ([] !== $targets) {
            $target = array_shift($targets);
            if ($target instanceof Array_ || $target instanceof List_) {
                // A destructuring writes each of its elements.
                foreach ($target->items as $item) {
                    if ($item instanceof ArrayItem) {
                        $targets[] = $item->value;
                    }
                }
            } elseif (null !== $id = PsalmInternals::variableId($target, $self, $source)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Whether a write of one of the ids changes the value the id names: the
     * same, one that holds it ("$m" for "$m[1]"), or one it holds.
     *
     * @param list<string> $written
     */
    private static function overlaps(string $id, array $written): bool
    {
        foreach ($written as $other) {
            [$short, $long] = \strlen($other) <= \strlen($id) ? [$other, $id] : [$id, $other];
            if ($short === $long || str_starts_with($long, $short.'[') || str_starts_with($long, $short.'->')) {
                return true;
            }
        }

        return false;
    }
}
