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
use PhpParser\Node\Expr\FuncCall;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\ExpressionAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Codebase\Functions;
use Psalm\Internal\Provider\NodeDataProvider;
use Psalm\NodeTypeProvider;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\Possibilities;

/**
 * Every use the plugin makes of Psalm's internal classes, which Psalm keeps
 * from one minor release to the next only as far as its own code needs:
 * asserting on a call's result, analysing an argument before Psalm does,
 * asking whether a function exists and reading a function's parameters.
 *
 * One check stands for the whole set: each method Psalm 6.19 and later have
 * is still there. Should a release move one, every call is left to Psalm's
 * stubs, as a call the plugin cannot read is: no assertion, no callback
 * type, and a namespaced call that may reach a function of the namespace is
 * not read.
 *
 * @internal
 */
final class PsalmInternals
{
    /**
     * The internal methods the plugin calls, by class.
     */
    private const METHODS = [
        NodeDataProvider::class => ['setIfTrueAssertions', 'setIfFalseAssertions'],
        ExpressionAnalyzer::class => ['analyze'],
        Functions::class => ['functionExists'],
    ];

    private static ?bool $available = null;

    /**
     * Whether the running Psalm has every internal method the plugin calls.
     */
    public static function available(): bool
    {
        if (null === self::$available) {
            $available = true;
            foreach (self::METHODS as $class => $methods) {
                foreach ($methods as $method) {
                    $available = $available && method_exists($class, $method);
                }
            }
            self::$available = $available;
        }

        return self::$available;
    }

    /**
     * Sets what holds where the call is true and, unless null, where it is
     * false. False when Psalm cannot be told, which leaves the call to its
     * stub.
     *
     * @param list<Possibilities>      $ifTrue
     * @param list<Possibilities>|null $ifFalse
     */
    public static function assert(NodeTypeProvider $nodeTypes, FuncCall $call, array $ifTrue, ?array $ifFalse): bool
    {
        if (!self::available() || !$nodeTypes instanceof NodeDataProvider) {
            return false;
        }

        $nodeTypes->setIfTrueAssertions($call, $ifTrue);
        if (null !== $ifFalse) {
            $nodeTypes->setIfFalseAssertions($call, $ifFalse);
        }

        return true;
    }

    /**
     * Analyses the expression now, while Psalm asks for the parameters of
     * the call it is an argument of, before it analyses the arguments itself.
     * False when it is not analysed: under a source of another kind than the
     * statements Psalm analyses, which it never is there.
     */
    public static function analyse(Expr $expr, StatementsSource $source, Context $context): bool
    {
        if (!self::available() || !$source instanceof StatementsAnalyzer) {
            return false;
        }

        return false !== ExpressionAnalyzer::analyze($source, $expr, $context);
    }

    /**
     * Whether Psalm knows a function of that lowercase name; true when it
     * cannot be asked, so a call that may reach it is left alone.
     *
     * @param lowercase-string $function
     */
    public static function functionExists(StatementsSource $source, string $function): bool
    {
        if (!self::available() || !$source instanceof StatementsAnalyzer) {
            return true;
        }

        return $source->getCodebase()->functions->functionExists($source, $function);
    }

    /**
     * The parameters Psalm's stub declares for a function, or null when they
     * cannot be read.
     *
     * @param non-empty-string $function
     *
     * @return list<FunctionLikeParameter>|null
     */
    public static function parameters(StatementsSource $source, string $function): ?array
    {
        if (!self::available() || !$source instanceof StatementsAnalyzer) {
            return null;
        }

        try {
            return $source->getCodebase()->getFunctionLikeStorage($source, $function)->params;
        } catch (\UnexpectedValueException) {
            return null; // Never for a function the stubs declare.
        }
    }
}
