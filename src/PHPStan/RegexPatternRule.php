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
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\PrettyPrinter\Standard;
use PHPRegex\Automata\TrivialMatchClassifier;
use PHPRegex\Linter\Config\UnsupportedTargetException;
use PHPRegex\Linter\Extraction\InteropPresets;
use PHPRegex\Linter\Extraction\PatternFunction;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PregFunctions;
use PHPRegex\Parser\Analysis\GroupNumbering;
use PHPRegex\Parser\Analysis\GroupNumberingCollector;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPStan\Analyser\Scope;
use PHPStan\Php\PhpVersion;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Type;

/**
 * Reports, in `preg_*` calls, the patterns the targeted PHP and PCRE2 refuse
 * while the engine running PHPStan compiles them (PHPStan core reports what
 * the running engine refuses); lint, ReDoS risks and optimizations on demand.
 *
 * The "phpRegex" parameter is ConfigArray, every key optional, as the
 * extension's schema shapes it; the constructors read it again, for a rule
 * built by hand.
 *
 * @phpstan-type ConfigArray array{
 *     phpVersion?: string|int|null,
 *     pcreVersion?: string|null,
 *     locale?: string|null,
 *     interop?: list<string>|null,
 *     checks?: array{
 *         lint?: array{enabled?: bool},
 *         redos?: array{enabled?: bool, threshold?: string},
 *         optimizations?: array{
 *             enabled?: bool,
 *             minSavings?: int,
 *             options?: array{
 *                 digits?: bool,
 *                 word?: bool,
 *                 ranges?: bool,
 *                 canonicalizeCharClasses?: bool,
 *                 possessive?: bool,
 *                 factorize?: bool,
 *                 minQuantifierCount?: int,
 *                 verifyWithAutomata?: bool,
 *             },
 *         },
 *         complexity?: array{enabled?: bool, threshold?: int},
 *     },
 * }
 *
 * @implements Rule<CallLike>
 */
final class RegexPatternRule implements Rule
{
    public const IDENTIFIER_INVALID_FOR_TARGET = 'regex.invalidForTarget';
    public const IDENTIFIER_REDOS = 'regex.redos';

    /**
     * A "@regex-target" marker whose constraint cannot be read.
     */
    public const IDENTIFIER_TARGET_INVALID = 'regex.target.invalid';

    /**
     * A "@regex-target" marker that keeps no version of PHPStan's range.
     */
    public const IDENTIFIER_TARGET_EMPTY = 'regex.target.empty';
    public const IDENTIFIER_OPTIMIZATION = 'regex.optimization';

    public const IDENTIFIER_TRIVIAL_MATCH = 'regex.trivialMatch';

    /**
     * One attempt proven linear, the unanchored search that retries it
     * quadratic.
     */
    public const IDENTIFIER_REDOS_SEARCH = 'regex.redos.search';

    /**
     * A constant replacement of preg_replace() or preg_filter() refers to a
     * group the pattern does not have; always reported.
     */
    public const IDENTIFIER_REPLACEMENT_UNDEFINED_GROUP = 'regex.replacement.undefinedGroup';

    private readonly PatternChecker $checker;

    /**
     * The calls carrying a pattern, as `regex lint` reads them: the native
     * functions, their namespaced drop-ins and the static methods of the
     * wrapper libraries the "interop" parameter names.
     */
    private readonly PatternFunctionRegistry $registry;

    private ?TrivialMatchClassifier $trivialMatches = null;

    /**
     * @param ConfigArray                        $config             the "phpRegex" parameter: "phpVersion", "pcreVersion",
     *                                                               "interop" (the presets read, every one when null) and
     *                                                               "checks" ("lint", "redos", "optimizations"),
     *                                                               every key optional
     * @param PhpVersion|null                    $phpVersion         the PHP version PHPStan analyses the project for: patterns are
     *                                                               judged for it, with the PCRE2 it bundles, unless "phpVersion"
     *                                                               is "runtime" or names a version, and "pcreVersion" a release
     * @param int|array{min: int, max: int}|null $phpVersionRange    PHPStan's "phpVersion" parameter: with {min, max}, a pattern is
     *                                                               also validated at each later PHP up to max where a rule of the
     *                                                               library changes, unless "phpVersion" names one version
     * @param ReflectionProvider|null            $reflectionProvider tells whether PHP_VERSION_ID names the global constant
     *                                                               in a namespace, for a guard written without a leading backslash
     *
     * @throws InvalidRegexOptionException when "phpVersion", "pcreVersion" or "checks.redos.threshold" cannot be read,
     *                                     or "interop" names a preset that does not exist
     * @throws UnsupportedTargetException  when the PHP to judge for ships PCRE1, as regex lint refuses it
     */
    public function __construct(
        array $config = [],
        ?PhpVersion $phpVersion = null,
        int|array|null $phpVersionRange = null,
        ?ReflectionProvider $reflectionProvider = null,
    ) {
        $this->checker = new PatternChecker($config, $phpVersion, $phpVersionRange, $reflectionProvider);
        $interop = $config['interop'] ?? null;
        $presets = \is_array($interop) ? array_values(array_filter($interop, \is_string(...))) : InteropPresets::DEFAULT_PRESETS;
        foreach ($presets as $preset) {
            if (!InteropPresets::exists($preset)) {
                throw new InvalidRegexOptionException(InteropPresets::unknownError($preset, 'phpRegex.interop'));
            }
        }
        $this->registry = PatternFunctionRegistry::native()->withPresets($presets);
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return array<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof StaticCall) {
            return $this->processStaticCall($node, $scope);
        }

        if (!$node instanceof FuncCall || !$node->name instanceof Name) {
            return [];
        }

        // Safe\preg_match() and the other drop-ins read as the function
        // they stand for.
        $functionName = $this->registry->lookupFunction($node->name->toString())->label ?? '';
        $subject = PregFunctions::subject($functionName);
        if (null === $subject) {
            return [];
        }

        $args = $node->getArgs();
        $patternArg = self::argument($args, 0, 'pattern');
        if (null === $patternArg) {
            return [];
        }

        // A constant subject cannot carry an attack: the call backtracks the
        // same way on every run, or never.
        [$subjectPosition, $subjectName] = $subject;
        $subjectArg = self::argument($args, $subjectPosition, $subjectName);
        $constantSubject = null !== $subjectArg && $scope->getType($subjectArg)->isConstantValue()->yes();

        if ('preg_replace_callback_array' === $functionName) {
            return $this->processPregReplaceCallbackArray($patternArg, $scope, $node->getStartLine(), $functionName, $constantSubject);
        }

        // preg_match($pattern, $subject) alone, its result the only output.
        $plainMatch = $this->checker->optimizationsEnabled() && 'preg_match' === $functionName && 2 === \count($args) && null !== $subjectArg;

        $patternType = $scope->getType($patternArg);
        // A pattern written as the argument has its literal at hand, to tell
        // a character written raw from one PHP's escapes produced; one read
        // from an array has none.
        $literalOffset = PatternChecker::literalOffset($patternArg);
        $patterns = array_fill_keys(self::constantStrings($patternType), $literalOffset);
        // An array of patterns is a pattern each: preg_match() and the
        // others take a string only, PHP refuses an array there.
        if (\in_array($functionName, PregFunctions::PATTERN_LISTS, true)) {
            foreach ($patternType->getConstantArrays() as $patternArray) {
                foreach ($patternArray->getValueTypes() as $patternValue) {
                    $patterns += array_fill_keys(self::constantStrings($patternValue), null);
                }
            }
        }

        $errors = [];
        foreach ($patterns as $pattern => $offset) {
            $pattern = (string) $pattern;
            $errors = array_merge(
                $errors,
                $this->checker->check($pattern, $node->getStartLine(), $scope, $this->formatSource($functionName), $constantSubject, $offset),
            );

            if ($plainMatch) {
                $errors = array_merge($errors, $this->trivialMatch($pattern, $subjectArg, $node->getStartLine()));
            }
        }

        $replacementArg = 'preg_replace' === $functionName || 'preg_filter' === $functionName ? self::argument($args, 1, 'replacement') : null;
        if (null !== $replacementArg) {
            $errors = array_merge($errors, $this->undefinedReplacementGroups($scope->getType($patternArg), $scope->getType($replacementArg), $node->getStartLine(), $functionName));
        }

        return $errors;
    }

    /**
     * A static method of a wrapper library, judged as `regex lint` judges
     * it: the pattern at the argument the library takes it, the keys of a
     * map where the library reads them. The subject is not told apart, so
     * a constant one does not quiet the ReDoS report.
     *
     * @return array<IdentifierRuleError>
     */
    private function processStaticCall(StaticCall $node, Scope $scope): array
    {
        if (!$node->class instanceof Name || !$node->name instanceof Identifier || $node->isFirstClassCallable()) {
            return [];
        }

        $function = $this->registry->lookupMethod($scope->resolveName($node->class), $node->name->toString());
        if (null === $function) {
            return [];
        }

        $args = $node->getArgs();
        $errors = [];
        foreach ($function->eachArgument() as $carrier) {
            $patternArg = self::patternArgument($args, $carrier);
            if (null === $patternArg) {
                continue;
            }

            $patternType = $scope->getType($patternArg);
            $patterns = array_fill_keys(self::constantStrings($patternType), PatternChecker::literalOffset($patternArg));
            $keys = $carrier->keysArePatterns && $this->keysArePatterns($carrier, $args, $scope);
            foreach ($patternType->getConstantArrays() as $patternArray) {
                // Where a replacement decides, a list holds its patterns in
                // its values: the keys are patterns when the first is a string.
                $readKeys = $keys && (null === $carrier->replacementIndex || ($patternArray->getKeyTypes()[0] ?? null)?->isString()->yes() === true);
                foreach ($readKeys ? $patternArray->getKeyTypes() : $patternArray->getValueTypes() as $patternValue) {
                    $patterns += array_fill_keys(self::constantStrings($patternValue), null);
                }
            }

            foreach ($patterns as $pattern => $offset) {
                $errors = array_merge(
                    $errors,
                    $this->checker->check((string) $pattern, $node->getStartLine(), $scope, $this->formatSource($function->label), false, $offset),
                );
            }
        }

        return $errors;
    }

    /**
     * Whether a map passed to a function reading keys holds its patterns
     * in the keys: always, unless a replacement decides, as for nette's
     * Strings::replace(), where a callable replacement makes the values
     * the patterns.
     *
     * @param array<Arg> $args
     */
    private function keysArePatterns(PatternFunction $function, array $args, Scope $scope): bool
    {
        if (null === $function->replacementIndex) {
            return true;
        }

        $replacement = self::argument($args, $function->replacementIndex, 'replacement');

        return null === $replacement || $scope->getType($replacement)->isCallable()->no();
    }

    /**
     * The pattern argument of a wrapper: by the name of its pattern
     * parameter, case included as PHP compares it, when the call names its
     * arguments, by position otherwise.
     *
     * @param array<Arg> $args
     */
    private static function patternArgument(array $args, PatternFunction $carrier): ?Expr
    {
        foreach ($carrier->parameterNames as $name) {
            foreach ($args as $arg) {
                if (null !== $arg->name && $name === $arg->name->toString()) {
                    return $arg->value;
                }
            }
        }

        return self::argument($args, $carrier->argumentIndex, '');
    }

    /**
     * A preg_match() a string function answers alike, as the automata prove.
     *
     * @return list<IdentifierRuleError>
     */
    private function trivialMatch(string $pattern, Expr $subject, int $lineNumber): array
    {
        if (!$this->checker->runningEngineCompiles($pattern)) {
            return [];
        }

        $match = ($this->trivialMatches ??= new TrivialMatchClassifier($this->checker->parser()))->classify($pattern);
        if (null === $match) {
            return [];
        }

        return [ErrorFactory::trivialMatch($pattern, $match, (new Standard())->prettyPrintExpr($subject), $lineNumber)];
    }

    /**
     * The references of a constant replacement to a group the pattern does
     * not have, and the "${name}" PHP never substitutes. An array of patterns
     * takes the replacement of the same position in an array of
     * replacements (none: the empty string), or the one string replacement.
     *
     * When the pattern and the replacement both vary, they may vary together
     * (a ternary on one condition, two maps read with one key), which the
     * types do not keep: a reference is then reported only if no pattern
     * the call may take defines its group. A single pattern or a single
     * replacement meets every value of the other, and is fully checked.
     *
     * @return list<IdentifierRuleError>
     */
    private function undefinedReplacementGroups(Type $patternType, Type $replacementType, int $lineNumber, string $functionName): array
    {
        $stringReplacements = self::constantStrings($replacementType);

        /** @var list<array{list<string>, list<string>}> $pairs the patterns a call may take, with the replacements they may take */
        $pairs = [[self::constantStrings($patternType), $stringReplacements]];

        $replacementArrays = $replacementType->getConstantArrays();
        // A key that may be missing moves every entry after it.
        $certainPositions = [] === array_filter($replacementArrays, static fn (ConstantArrayType $array): bool => [] !== $array->getOptionalKeys());

        /** @var array<int, list<string>> $positions the patterns each position may hold */
        $positions = [];
        foreach ($patternType->getConstantArrays() as $patterns) {
            if (!$certainPositions || [] !== $patterns->getOptionalKeys()) {
                continue;
            }

            foreach ($patterns->getValueTypes() as $position => $patternValue) {
                $positions[$position] = [...$positions[$position] ?? [], ...self::constantStrings($patternValue)];
            }
        }

        foreach ($positions as $position => $patterns) {
            $replacements = $stringReplacements;
            foreach ($replacementArrays as $replacementArray) {
                $replacements = [...$replacements, ...self::constantStrings($replacementArray->getValueTypes()[$position] ?? null)];
            }

            $pairs[] = [$patterns, $replacements];
        }

        $errors = [];
        foreach ($pairs as [$patterns, $replacements]) {
            $patterns = array_values(array_unique($patterns));
            $replacements = array_values(array_unique($replacements));
            if ([] === $replacements) {
                continue;
            }

            $correlated = \count($patterns) > 1 && \count($replacements) > 1;
            /** @var list<array{string, GroupNumbering}> $numberings */
            $numberings = [];
            foreach ($patterns as $pattern) {
                $numbering = $this->groupNumbering($pattern);
                if (null !== $numbering) {
                    $numberings[] = [$pattern, $numbering];
                } elseif ($correlated) {
                    // A pattern that cannot be read may define any group.
                    continue 2;
                }
            }

            foreach ($replacements as $replacement) {
                foreach (ReplacementReferences::of($replacement) as $reference) {
                    $found = [];
                    foreach ($numberings as [$pattern, $numbering]) {
                        $error = ErrorFactory::undefinedReplacementGroup($reference, $numbering, $pattern, $functionName);
                        if (null !== $error) {
                            $found[] = $error;
                        } elseif ($correlated) {
                            // One of the patterns defines it.
                            continue 2;
                        }
                    }

                    foreach ($found as $error) {
                        if (isset($errors[$error[0]])) {
                            continue;
                        }

                        $errors[$error[0]] = ErrorFactory::replacementGroup($error[0], $error[1], $lineNumber);
                    }
                }
            }
        }

        return array_values($errors);
    }

    /**
     * The group numbers of a pattern the running engine compiles, null for
     * one it refuses: PHPStan core reports that one.
     */
    private function groupNumbering(string $pattern): ?GroupNumbering
    {
        if (!$this->checker->runningEngineCompiles($pattern)) {
            return null;
        }

        try {
            return (new GroupNumberingCollector())->collect($this->checker->parser()->parse($pattern));
        } catch (ExceptionInterface) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private static function constantStrings(?Type $type): array
    {
        $strings = [];
        foreach ($type?->getConstantStrings() ?? [] as $constantString) {
            $strings[] = $constantString->getValue();
        }

        return $strings;
    }

    /**
     * The argument passed for a parameter, by name when the call names its
     * arguments, by position otherwise; null when it cannot be told.
     *
     * @param array<Arg> $args
     */
    private static function argument(array $args, int $position, string $name): ?Expr
    {
        foreach ($args as $index => $arg) {
            if (null !== $arg->name) {
                if ($name === $arg->name->toString()) {
                    return $arg->value;
                }

                continue;
            }

            if ($arg->unpack) {
                return null;
            }

            if ($position === $index) {
                return $arg->value;
            }
        }

        return null;
    }

    /**
     * @return array<IdentifierRuleError>
     */
    private function processPregReplaceCallbackArray(Expr $map, Scope $scope, int $lineNumber, string $functionName, bool $constantSubject): array
    {
        // The keys are the patterns, wherever the map is written: in the
        // call, in a variable or in a constant.
        // A map written in the call has each key's literal at hand.
        $offsets = [];
        if ($map instanceof Array_) {
            foreach ($map->items as $item) {
                if ($item instanceof ArrayItem && $item->key instanceof String_) {
                    $offsets[$item->key->value] = PatternChecker::literalOffset($item->key);
                }
            }
        }

        $patterns = [];
        foreach ($scope->getType($map)->getConstantArrays() as $callbacks) {
            foreach ($callbacks->getKeyTypes() as $key) {
                $patterns = [...$patterns, ...self::constantStrings($key)];
            }
        }

        $errors = [];
        foreach (array_values(array_unique($patterns)) as $pattern) {
            $errors = array_merge(
                $errors,
                $this->checker->check($pattern, $lineNumber, $scope, $this->formatSource($functionName), $constantSubject, $offsets[$pattern] ?? null),
            );
        }

        return $errors;
    }

    private function formatSource(string $functionName): string
    {
        return 'php:'.$functionName.'()';
    }
}
