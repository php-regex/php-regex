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

namespace PHPRegex\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPRegex\Parser\Attribute\RegexPattern;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPStan\Analyser\Scope;
use PHPStan\Php\PhpVersion;
use PHPStan\Reflection\AttributeReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ExtendedParametersAcceptor;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\TypeCombinator;

/**
 * Reports the constant patterns passed to a parameter marked
 * #[PHPRegex\Parser\Attribute\RegexPattern] or PhpStorm's
 * #[JetBrains\PhpStorm\Language('RegExp')], in a call to a function, a
 * static or instance method, or a constructor: the patterns the engine
 * running PHPStan refuses (PHPStan core reads only preg_*() calls), those
 * the targeted PHP and PCRE2 refuse, and, as RegexPatternRule does, lint,
 * ReDoS risks and optimizations on demand.
 *
 * @implements Rule<CallLike>
 */
final readonly class RegexPatternArgumentRule implements Rule
{
    /**
     * A pattern the engine running PHPStan refuses: the identifier PHPStan
     * core gives the same error in a preg_*() call.
     */
    public const IDENTIFIER_INVALID = 'regexp.pattern';

    private const PHPSTORM_LANGUAGE = 'jetbrains\phpstorm\language';

    private PatternChecker $checker;

    /**
     * @param array<string, mixed> $config          the "phpRegex" parameter, as RegexPatternRule reads it
     * @param PhpVersion|null      $phpVersion      the PHP version PHPStan analyses the project for
     * @param mixed                $phpVersionRange PHPStan's "phpVersion" parameter
     *
     * @throws InvalidRegexOptionException when "phpVersion", "pcreVersion" or "checks.redos.threshold" cannot be read
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        array $config = [],
        ?PhpVersion $phpVersion = null,
        mixed $phpVersionRange = null,
    ) {
        $this->checker = new PatternChecker($config, $phpVersion, $phpVersionRange);
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof CallLike || $node->isFirstClassCallable()) {
            return [];
        }

        $callee = $this->callee($node, $scope);
        if (null === $callee) {
            return [];
        }

        [$name, $variants] = $callee;
        $source = 'php:'.$name.'()';
        $args = $node->getArgs();

        $errors = [];
        foreach (self::markedParameters($variants) as $position => [$parameterName, $variadic]) {
            foreach (self::arguments($args, $position, $parameterName, $variadic) as $argument) {
                foreach ($scope->getType($argument)->getConstantStrings() as $constantString) {
                    $errors = [...$errors, ...$this->patternErrors($constantString->getValue(), $node->getLine(), $scope, $source)];
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function patternErrors(string $pattern, int $lineNumber, Scope $scope, string $source): array
    {
        $refusal = (new PcreEngine())->compile($pattern);
        if (null !== $refusal) {
            return [RuleErrorBuilder::message(\sprintf(
                'Regex pattern is invalid: %s%s',
                $refusal->message,
                str_ends_with($refusal->message, '.') ? '' : '.',
            ))
                ->line($lineNumber)
                ->identifier(self::IDENTIFIER_INVALID)
                ->build()];
        }

        return array_values($this->checker->check($pattern, $lineNumber, $scope, $source, false));
    }

    /**
     * The name of the function or method the call reaches, with its
     * signatures; null when PHPStan cannot tell it, or for one of PHP's own.
     *
     * @return array{string, list<ExtendedParametersAcceptor>}|null
     */
    private function callee(CallLike $node, Scope $scope): ?array
    {
        if ($node instanceof FuncCall) {
            if (!$node->name instanceof Name || !$this->reflectionProvider->hasFunction($node->name, $scope)) {
                return null;
            }

            $function = $this->reflectionProvider->getFunction($node->name, $scope);

            return $function->isBuiltin() ? null : [$function->getName(), $function->getVariants()];
        }

        // PHPStan reads "$a?->b()" as "$a->b()" too: one report, not two.
        if ($node instanceof MethodCall) {
            if (!$node->name instanceof Identifier) {
                return null;
            }

            $method = $scope->getMethodReflection(TypeCombinator::removeNull($scope->getType($node->var)), $node->name->toString());

            return self::method($method);
        }

        if ($node instanceof StaticCall) {
            if (!$node->name instanceof Identifier) {
                return null;
            }

            // "Str::", "self::", "parent::", or "$class::" with the class-string PHPStan knows.
            $class = $node->class instanceof Name
                ? $scope->resolveTypeByName($node->class)
                : $scope->getType($node->class)->getObjectTypeOrClassStringObjectType();

            return self::method($scope->getMethodReflection($class, $node->name->toString()));
        }

        if ($node instanceof New_) {
            if (!$node->class instanceof Name) {
                return null;
            }

            $class = $scope->resolveName($node->class);
            if (!$this->reflectionProvider->hasClass($class)) {
                return null;
            }

            $reflection = $this->reflectionProvider->getClass($class);

            return $reflection->hasConstructor() ? self::method($reflection->getConstructor()) : null;
        }

        return null;
    }

    /**
     * @return array{string, list<ExtendedParametersAcceptor>}|null
     */
    private static function method(?ExtendedMethodReflection $method): ?array
    {
        if (null === $method || $method->getDeclaringClass()->isBuiltin()) {
            return null;
        }

        return [$method->getDeclaringClass()->getName().'::'.$method->getName(), $method->getVariants()];
    }

    /**
     * The zero-based position of each parameter that receives a regex, with
     * its name and whether it is variadic.
     *
     * @param list<ExtendedParametersAcceptor> $variants
     *
     * @return array<int, array{string, bool}>
     */
    private static function markedParameters(array $variants): array
    {
        $marked = [];
        foreach ($variants as $variant) {
            foreach ($variant->getParameters() as $position => $parameter) {
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
        $name = strtolower(ltrim($attribute->getName(), '\\'));
        if (strtolower(RegexPattern::class) === $name) {
            return true;
        }

        if (self::PHPSTORM_LANGUAGE !== $name) {
            return false;
        }

        foreach ($attribute->getArgumentTypes() as $type) {
            foreach ($type->getConstantStrings() as $constantString) {
                if ('regexp' === strtolower($constantString->getValue())) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The arguments passed for a parameter: by name when the call names it,
     * by position otherwise, every one from its position on for a variadic
     * parameter; none after an unpacked argument, whose length is unknown.
     *
     * @param array<Arg> $args
     *
     * @return list<Expr>
     */
    private static function arguments(array $args, int $position, string $name, bool $variadic): array
    {
        $arguments = [];
        foreach (array_values($args) as $index => $arg) {
            if (null !== $arg->name) {
                if ($name === $arg->name->toString()) {
                    $arguments[] = $arg->value;
                }

                continue;
            }

            if ($arg->unpack) {
                break;
            }

            if ($position === $index || ($variadic && $index > $position)) {
                $arguments[] = $arg->value;
            }
        }

        return $arguments;
    }
}
