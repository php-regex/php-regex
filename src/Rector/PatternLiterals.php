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

namespace PHPRegex\Rector;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Scalar\String_;
use PHPRegex\Parser\Attribute\RegexPattern;
use PHPStan\Reflection\AttributeReflection;
use PHPStan\Reflection\ExtendedParameterReflection;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptor;
use Rector\Reflection\ReflectionResolver;

/**
 * The string literals a call hands to a regex engine, each with the way to
 * put a rewritten one in its place: the first argument of a preg_*() call,
 * the keys of the array preg_replace_callback_array() takes, and each
 * argument a function or a static method marks with #[RegexPattern] or
 * PhpStorm's #[Language('RegExp')] receives. A pattern held by a constant
 * is left alone here: rewriting the call would inline the constant (see
 * PrivateConstants for the declaration of a private one).
 *
 * @internal
 */
final class PatternLiterals
{
    private const PHPSTORM_LANGUAGE = 'jetbrains\phpstorm\language';

    private function __construct() {}

    /**
     * @param bool $pregCall      whether the call is one of TargetRange::FUNCTIONS
     * @param bool $callbackArray whether the call is preg_replace_callback_array()
     *
     * @return list<array{String_, \Closure(String_): void}>
     */
    public static function of(CallLike $call, bool $pregCall, bool $callbackArray, ReflectionResolver $reflection): array
    {
        $literals = [];
        foreach (self::positions($call, $pregCall, $callbackArray, $reflection) as [$value, $replace]) {
            if ($value instanceof String_) {
                $literals[] = [$value, $replace];
            }
        }

        return $literals;
    }

    /**
     * What the call hands to a regex engine where a pattern goes, whatever
     * it is written as, each with the way to put a string literal in its
     * place.
     *
     * @param bool $pregCall      whether the call is one of TargetRange::FUNCTIONS
     * @param bool $callbackArray whether the call is preg_replace_callback_array()
     *
     * @return list<array{Expr, \Closure(String_): void}>
     */
    public static function positions(CallLike $call, bool $pregCall, bool $callbackArray, ReflectionResolver $reflection): array
    {
        if ($call->isFirstClassCallable()) {
            return [];
        }

        $args = $call->getArgs();
        if ($pregCall) {
            $first = $args[0] ?? null;

            return null === $first || null !== $first->name || $first->unpack ? [] : [[$first->value, static function (String_ $pattern) use ($first): void {
                $first->value = $pattern;
            }]];
        }

        if ($callbackArray) {
            return self::keys($args[0] ?? null);
        }

        $callee = $reflection->resolveFunctionLikeReflectionFromCall($call);
        if ($callee instanceof FunctionReflection ? $callee->isBuiltin() : (!$callee instanceof MethodReflection || $callee->getDeclaringClass()->isBuiltin())) {
            return [];
        }

        $positions = [];
        foreach (self::markedParameters($callee->getVariants()) as $position => [$name, $variadic]) {
            foreach ($args as $index => $arg) {
                $receives = null === $arg->name ? ($index === $position || ($variadic && $index > $position)) : $arg->name->toString() === $name;
                if ($receives && !$arg->unpack) {
                    $positions[] = [$arg->value, static function (String_ $pattern) use ($arg): void {
                        $arg->value = $pattern;
                    }];
                }
            }
        }

        return $positions;
    }

    /**
     * The keys of the array an argument is, written in place.
     *
     * @return list<array{Expr, \Closure(String_): void}>
     */
    private static function keys(?Arg $array): array
    {
        if (null === $array || null !== $array->name || $array->unpack || !$array->value instanceof Array_) {
            return [];
        }

        $keys = [];
        foreach ($array->value->items as $item) {
            if (null !== $item->key) {
                $keys[] = [$item->key, static function (String_ $pattern) use ($item): void {
                    $item->key = $pattern;
                }];
            }
        }

        return $keys;
    }

    /**
     * The zero-based position of each parameter that receives a regex, with
     * its name and whether it is variadic.
     *
     * @param list<ParametersAcceptor> $variants
     *
     * @return array<int, array{string, bool}>
     */
    private static function markedParameters(array $variants): array
    {
        $marked = [];
        foreach ($variants as $variant) {
            foreach ($variant->getParameters() as $position => $parameter) {
                if (!$parameter instanceof ExtendedParameterReflection) {
                    continue; // never taken: PHPStan 2 extends every parameter of a function or a method
                }

                foreach ($parameter->getAttributes() as $attribute) {
                    if (self::marksRegex($attribute)) {
                        $marked[$position] = [$parameter->getName(), $parameter->isVariadic()];

                        break;
                    }
                }
            }
        }

        return $marked;
    }

    /**
     * #[RegexPattern], or PhpStorm's #[Language('RegExp')].
     */
    private static function marksRegex(AttributeReflection $attribute): bool
    {
        $name = self::lower(ltrim($attribute->getName(), '\\'));
        if (self::lower(RegexPattern::class) === $name) {
            return true;
        }

        if (self::PHPSTORM_LANGUAGE !== $name) {
            return false;
        }

        foreach ($attribute->getArgumentTypes() as $type) {
            foreach ($type->getConstantStrings() as $constantString) {
                if ('regexp' === self::lower($constantString->getValue())) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The text with its ASCII letters lowered, whatever the locale says.
     */
    private static function lower(string $text): string
    {
        return strtr($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }
}
