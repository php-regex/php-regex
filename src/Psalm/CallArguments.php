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

use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\BitwiseOr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PHPRegex\Linter\Extraction\PregFunctions;
use PHPRegex\Parser\Text\Ascii;
use Psalm\Context;
use Psalm\NodeTypeProvider;
use Psalm\StatementsSource;

/**
 * The arguments of a preg_* call, bound to the parameters of the function
 * the call reaches as PHP binds them, and the constant patterns among them.
 *
 * @internal
 */
final class CallArguments
{
    /**
     * The parameters of each function, in order: PHP binds the arguments to
     * them by position, then by name. The pattern comes first in each but
     * preg_quote(), which builds one.
     */
    public const PARAMETERS = PregFunctions::PARAMETERS + ['preg_quote' => ['str', 'delimiter']];

    /**
     * The argument of each parameter, as PHP binds them: by position, then
     * by name. Null when the call unpacks arguments or names a parameter
     * the function does not have.
     *
     * @param list<Arg>    $arguments
     * @param list<string> $parameters
     *
     * @return array<string, Expr>|null
     */
    public static function bind(array $arguments, array $parameters): ?array
    {
        $bound = [];
        foreach ($arguments as $position => $argument) {
            if ($argument->unpack) {
                return null;
            }

            $parameter = $argument->name?->toString() ?? $parameters[$position] ?? null;
            if (null === $parameter || !\in_array($parameter, $parameters, true)) {
                return null;
            }

            $bound[$parameter] = $argument->value;
        }

        return $bound;
    }

    /**
     * The global preg_* function the call reaches, lowercase, or null. An
     * unqualified call in a namespace reaches the namespace's function of
     * that name when there is one, as PHP resolves it.
     */
    public static function globalFunction(Name $name, StatementsSource $source): ?string
    {
        $resolved = $name->getAttribute('resolvedName');
        $namespaced = $name->getAttribute('namespacedName');
        $function = match (true) {
            $name instanceof FullyQualified => $name->toString(),
            // "use function" imported it, or the name is qualified.
            \is_string($resolved) => $resolved,
            // Psalm keys its functions by strtolower(), locale and all: the
            // name is lowered the same way to be found. A Psalm that cannot
            // be asked leaves the call alone.
            \is_string($namespaced) && PsalmInternals::functionExists($source, strtolower($namespaced)) => null,
            default => $name->toString(),
        };

        $function = null === $function ? null : Ascii::lower(ltrim($function, '\\'));

        return null !== $function && isset(self::PARAMETERS[$function]) ? $function : null;
    }

    /**
     * Each constant pattern of the argument, with the node an issue points
     * at: the argument itself when its type is one literal string, else
     * each element of an array of patterns (each key for
     * preg_replace_callback_array()).
     *
     * @return list<array{string, Expr}>
     */
    public static function constantPatterns(string $function, Expr $argument, NodeTypeProvider $nodeTypes): array
    {
        $pattern = self::literal($argument, $nodeTypes);
        if (null !== $pattern) {
            return 'preg_replace_callback_array' === $function ? [] : [[$pattern, $argument]];
        }

        $keys = 'preg_replace_callback_array' === $function;
        if (!$argument instanceof Array_ || (!$keys && !\in_array($function, PregFunctions::PATTERN_LISTS, true))) {
            return [];
        }

        $patterns = [];
        foreach ($argument->items as $item) {
            $node = $item instanceof ArrayItem && !$item->unpack ? ($keys ? $item->key : $item->value) : null;
            $pattern = null === $node ? null : self::literal($node, $nodeTypes);
            if (null !== $node && null !== $pattern) {
                $patterns[] = [$pattern, $node];
            }
        }

        return $patterns;
    }

    /**
     * The value of an expression whose type is one literal string: what the
     * plugin calls a constant pattern.
     */
    public static function literal(Expr $node, NodeTypeProvider $nodeTypes): ?string
    {
        $type = $nodeTypes->getType($node);

        return null !== $type && $type->isSingleStringLiteral() ? $type->getSingleStringLiteral()->value : null;
    }

    /**
     * Whether the argument was analysed, which it is only when it writes
     * nothing: analysing it again, in its turn, changes nothing either.
     */
    public static function analysedInPlace(Expr $argument, StatementsSource $source, Context $context): bool
    {
        return self::writesNothing($argument, $source) && PsalmInternals::analyse($argument, $source, $context);
    }

    /**
     * Whether analysing the expression changes nothing: a literal, a
     * constant, a variable, or a concatenation or an | of those and of
     * preg_quote() calls.
     */
    private static function writesNothing(Expr $expr, StatementsSource $source): bool
    {
        if ($expr instanceof FuncCall) {
            if (!$expr->name instanceof Name || $expr->isFirstClassCallable() || 'preg_quote' !== self::globalFunction($expr->name, $source)) {
                return false;
            }

            foreach ($expr->getArgs() as $argument) {
                if ($argument->unpack || $argument->byRef || !self::writesNothing($argument->value, $source)) {
                    return false;
                }
            }

            return true;
        }

        return match (true) {
            $expr instanceof String_, $expr instanceof Int_, $expr instanceof ConstFetch, $expr instanceof Variable => true,
            $expr instanceof ClassConstFetch => $expr->class instanceof Name && $expr->name instanceof Identifier,
            $expr instanceof Concat, $expr instanceof BitwiseOr => self::writesNothing($expr->left, $source) && self::writesNothing($expr->right, $source),
            default => false,
        };
    }
}
