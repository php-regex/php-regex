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

use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use Psalm\Context;
use Psalm\FileSource;
use Psalm\StatementsSource;
use Psalm\Storage\Assertion;
use Psalm\Storage\Assertion\NestedAssertions;
use Psalm\Storage\Possibilities;

/**
 * What holds on a preg_match() call's result, written where Psalm 7.11 and
 * later read it: they read the condition a preg_match() call stands in from
 * their cache of assertions, and otherwise from the call's pattern alone,
 * never from the assertions set on the call. The plugin writes in that
 * cache what it sets on the call, as Psalm read it from the call up to
 * 7.10, beside what Psalm reads of the pattern itself.
 *
 * Whether the running Psalm reads the call's assertions itself is asked of
 * it once, on the first call that has some: where it does, nothing more is
 * written.
 *
 * @phpstan-import-type Assertions from PsalmInternals
 *
 * @internal
 */
final class ConditionAssertions
{
    /**
     * Whether the running Psalm reads the assertions set on a preg_match()
     * call; null until a call asks it.
     */
    private static ?bool $psalmReadsThem = null;

    /**
     * What holds on the call's arguments, as Psalm reads it from the call's
     * assertions: where the call is true, an argument holds one of the
     * rules; where it is false, it holds none of them. An argument that
     * reads no variable, property or element of either is left out.
     *
     * @param list<Possibilities>      $ifTrue
     * @param list<Possibilities>|null $ifFalse
     *
     * @return Assertions
     */
    public static function translate(FuncCall $call, ?string $self, ?FileSource $source, array $ifTrue, ?array $ifFalse): array
    {
        $arguments = $call->getArgs();
        $translated = [];
        foreach ([[$ifTrue, false], [$ifFalse ?? [], true]] as [$possibilities, $negated]) {
            foreach ($possibilities as $possibility) {
                $rules = $possibility->rule;
                $index = $possibility->var_id;
                $variable = \is_int($index) && isset($arguments[$index]) && [] !== $rules ? PsalmInternals::variableId($arguments[$index]->value, $self, $source) : null;
                if (null !== $variable) {
                    $translated[] = [$variable => $negated ? array_map(static fn (Assertion $rule): array => [$rule->getNegation()], $rules) : [$rules]];
                }
            }
        }

        return $translated;
    }

    /**
     * Whether each of ours, a variable and the rules one of which it holds,
     * is among what Psalm read, in any element and in any order.
     *
     * @param Assertions $scraped
     * @param Assertions $ours
     */
    public static function holds(array $scraped, array $ours): bool
    {
        $read = [];
        foreach ($scraped as $element) {
            foreach ($element as $variable => $rules) {
                foreach ($rules as $alternatives) {
                    $read[self::key($variable, $alternatives)] = true;
                }
            }
        }

        foreach ($ours as $element) {
            foreach ($element as $variable => $rules) {
                foreach ($rules as $alternatives) {
                    if (!isset($read[self::key($variable, $alternatives)])) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Writes what holds where the preg_match() call is true in the cache
     * Psalm reads its condition from, unless Psalm reads the assertions just
     * set on the call itself. What Psalm reads of the pattern follows.
     *
     * @param list<Possibilities>      $ifTrue
     * @param list<Possibilities>|null $ifFalse
     */
    public static function cache(StatementsSource $source, ?string $self, FuncCall $call, array $ifTrue, ?array $ifFalse): void
    {
        if (true === self::$psalmReadsThem) {
            return;
        }

        $ours = self::translate($call, $self, $source, $ifTrue, $ifFalse);
        $scraped = [] === $ours ? null : PsalmInternals::scrapedAssertions($source, $call, $self);
        if (null === $scraped) {
            return;
        }

        self::$psalmReadsThem = self::holds($scraped, $ours);
        if (!self::$psalmReadsThem) {
            PsalmInternals::cacheAssertions($source, $call, [...$ours, ...$scraped]);
        }
    }

    /**
     * Where the result of a preg_match() call is assigned in a condition,
     * Psalm reads what holds of the variable from the call itself, never
     * from the call's cache: the assignment's cache nests what holds where
     * the call is true under the variable, as one, Psalm's reading of the
     * pattern included.
     */
    public static function cacheAssignment(StatementsSource $source, Context $context, Assign $assignment): void
    {
        $call = $assignment->expr;
        if (false !== self::$psalmReadsThem || !$context->inside_conditional || !$call instanceof FuncCall || !$call->name instanceof Name || 'preg_match' !== CallArguments::globalFunction($call->name, $source)) {
            return;
        }

        $merged = [];
        foreach (PsalmInternals::cachedAssertions($source, $call) ?? [] as $element) {
            foreach ($element as $variable => $rules) {
                $merged[$variable] = [...($merged[$variable] ?? []), ...$rules];
            }
        }

        // A cache could be read: the running Psalm keeps one, and has the
        // nested assertion, which PsalmInternals::cachesAssertions() checks.
        $variable = [] === $merged ? null : PsalmInternals::variableId($assignment->var, $context->self, $source);
        if (null !== $variable) {
            PsalmInternals::cacheAssertions($source, $assignment, [[$variable => [[new NestedAssertions($merged)]]]]);
        }
    }

    /**
     * @param list<Assertion> $alternatives
     */
    private static function key(string $variable, array $alternatives): string
    {
        $rules = array_map(static fn (Assertion $rule): string => (string) $rule, $alternatives);
        sort($rules);

        return serialize([$variable, $rules]);
    }
}
