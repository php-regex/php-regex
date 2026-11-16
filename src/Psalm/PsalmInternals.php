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
use Psalm\Internal\Provider\FunctionParamsProvider;
use Psalm\Internal\Provider\NodeDataProvider;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\Possibilities;

/**
 * Every use the plugin makes of Psalm's internal classes, which Psalm keeps
 * from one release to the next only as far as its own code needs:
 * asserting on a call's result, analysing an argument before Psalm does,
 * asking whether a function exists and reading a function's parameters.
 *
 * One check stands for the whole set: each method and property Psalm 6.19
 * and later have is still there. Should a release move one, every call is
 * left to Psalm's stubs, as a call the plugin cannot read is: no assertion,
 * no callback type, and a namespaced call that may reach a function of the
 * namespace is not read.
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

    /**
     * The internal properties the plugin reads, by class: the node data an
     * analyser reads its assertions from. Psalm 7 hands a plugin a view of
     * it, which resolves type variables; an assertion set on the view is
     * lost.
     */
    private const PROPERTIES = [
        StatementsAnalyzer::class => ['node_data'],
    ];

    private static ?bool $available = null;

    /**
     * Whether the running Psalm has every internal method the plugin calls
     * and every internal property it reads.
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
            foreach (self::PROPERTIES as $class => $properties) {
                foreach ($properties as $property) {
                    $available = $available && property_exists($class, $property);
                }
            }
            self::$available = $available;
        }

        return self::$available;
    }

    /**
     * Sets, on the node data the analyser reads, what holds where the call
     * is true and, unless null, where it is false. False when Psalm cannot
     * be told, which leaves the call to its stub: under a source of another
     * kind than the statements Psalm analyses, which it never is there.
     *
     * @param list<Possibilities>      $ifTrue
     * @param list<Possibilities>|null $ifFalse
     */
    public static function assert(StatementsSource $source, FuncCall $call, array $ifTrue, ?array $ifFalse): bool
    {
        if (!self::available() || !$source instanceof StatementsAnalyzer) {
            return false;
        }

        $source->node_data->setIfTrueAssertions($call, $ifTrue);
        if (null !== $ifFalse) {
            $source->node_data->setIfFalseAssertions($call, $ifFalse);
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
     * Has Psalm ask the plugin's parameters provider of a function before
     * any other: Psalm keeps the first parameters a provider gives, and from
     * 7.7 on types the callback of preg_replace_callback() itself, with a
     * provider it registers before the plugins. False when the providers
     * cannot be reached, which leaves them in their order.
     *
     * @param lowercase-string $function
     * @param class-string     $provider
     */
    public static function askFirst(string $function, string $provider): bool
    {
        // Every Psalm release the plugin reads keeps them in this property.
        $property = property_exists(FunctionParamsProvider::class, 'handlers') ? new \ReflectionProperty(FunctionParamsProvider::class, 'handlers') : null;
        $handlers = $property?->getValue();
        if (null === $property || !\is_array($handlers) || !\is_array($handlers[$function] ?? null)) {
            return false;
        }

        $first = [];
        $then = [];
        foreach ($handlers[$function] as $handler) {
            if ($handler instanceof \Closure && $provider === (new \ReflectionFunction($handler))->getClosureScopeClass()?->getName()) {
                $first[] = $handler;
            } else {
                $then[] = $handler;
            }
        }

        $handlers[$function] = [...$first, ...$then];
        $property->setValue(null, $handlers);

        return [] !== $first;
    }

    /**
     * Whether Psalm types the callback of preg_replace_callback() itself, as
     * 7.7 and later do from the flags of the call: a call the plugin cannot
     * read is then left to it.
     */
    public static function typesPregReplaceCallback(): bool
    {
        return class_exists('Psalm\\Internal\\Provider\\ParamsProvider\\PregReplaceCallbackParamsProvider');
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
