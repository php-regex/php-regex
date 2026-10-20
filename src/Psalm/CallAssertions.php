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

use PhpParser\Node\Expr;
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
use PhpParser\Node\Name;
use Psalm\NodeTypeProvider;
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
 * boolean.
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
    public static function assertOnResult(NodeTypeProvider $nodeTypes, FuncCall $call, array $ifTrue, array $ifFalse): void
    {
        PsalmInternals::assert($nodeTypes, $call, array_values(array_filter($ifTrue)), [] === $ifFalse ? null : array_values(array_filter($ifFalse)));
    }

    /**
     * Psalm reads a call's assertions through a comparison as if the call
     * returned a boolean: "preg_match(…) !== false" would type $matches as
     * the shape where no match left [] (0 !== false). Where a preg_match()
     * call is an operand, its assertions go: Psalm analyses the comparison
     * before it reads them. The stub asserts nothing on the call.
     */
    public static function dropAssertions(BinaryOp $comparison, StatementsSource $source): void
    {
        foreach ([$comparison->left, $comparison->right] as $operand) {
            if ($operand instanceof FuncCall && $operand->name instanceof Name && \in_array(CallArguments::globalFunction($operand->name, $source), ['preg_match', 'preg_match_all'], true)) {
                PsalmInternals::assert($source->getNodeTypeProvider(), $operand, [], []);
            }
        }
    }
}
