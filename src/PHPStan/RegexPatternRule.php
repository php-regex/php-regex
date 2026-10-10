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
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\PrettyPrinter\Standard;
use PHPRegex\Automata\TrivialMatchClassifier;
use PHPRegex\Parser\Analysis\GroupNumbering;
use PHPRegex\Parser\Analysis\GroupNumberingCollector;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPStan\Analyser\Scope;
use PHPStan\Php\PhpVersion;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Type;

/**
 * Reports, in `preg_*` calls, the patterns the targeted PHP and PCRE2 refuse
 * while the engine running PHPStan compiles them (PHPStan core reports what
 * the running engine refuses); lint, ReDoS risks and optimizations on demand.
 *
 * @phpstan-import-type GroupReference from ReplacementReferences
 *
 * @implements Rule<FuncCall>
 */
final class RegexPatternRule implements Rule
{
    public const IDENTIFIER_INVALID_FOR_TARGET = 'regex.invalidForTarget';
    public const IDENTIFIER_REDOS = 'regex.redos';
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

    /**
     * The preg functions the rule reads, with the position and name of their
     * subject parameter; the pattern comes first in each.
     */
    private const PREG_FUNCTION_MAP = [
        'preg_match' => [1, 'subject'],
        'preg_match_all' => [1, 'subject'],
        'preg_replace' => [2, 'subject'],
        'preg_replace_callback' => [2, 'subject'],
        'preg_split' => [1, 'subject'],
        'preg_grep' => [1, 'array'],
        'preg_filter' => [2, 'subject'],
        'preg_replace_callback_array' => [1, 'subject'],
    ];

    private readonly PatternChecker $checker;

    private ?TrivialMatchClassifier $trivialMatches = null;

    /**
     * @param array<string, mixed> $config          the "phpRegex" parameter: "phpVersion", "pcreVersion" and
     *                                              "checks" ("lint", "redos", "optimizations"), every key optional
     * @param PhpVersion|null      $phpVersion      the PHP version PHPStan analyses the project for: patterns are
     *                                              judged for it, with the PCRE2 it bundles, unless "phpVersion"
     *                                              is "runtime" or names a version, and "pcreVersion" a release
     * @param mixed                $phpVersionRange PHPStan's "phpVersion" parameter: with {min, max}, a pattern is
     *                                              also validated at each later PHP up to max where a rule of the
     *                                              library changes, unless "phpVersion" names one version
     *
     * @throws InvalidRegexOptionException when "phpVersion", "pcreVersion" or "checks.redos.threshold" cannot be read
     */
    public function __construct(array $config = [], ?PhpVersion $phpVersion = null, mixed $phpVersionRange = null)
    {
        $this->checker = new PatternChecker($config, $phpVersion, $phpVersionRange);
    }

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @return array<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof FuncCall) {
            return [];
        }

        if (!$node->name instanceof Name) {
            return [];
        }

        $functionName = $node->name->toLowerString();
        if (!isset(self::PREG_FUNCTION_MAP[$functionName])) {
            return [];
        }

        $args = $node->getArgs();
        $patternArg = self::argument($args, 0, 'pattern');
        if (null === $patternArg) {
            return [];
        }

        // A constant subject cannot carry an attack: the call backtracks the
        // same way on every run, or never.
        [$subjectPosition, $subjectName] = self::PREG_FUNCTION_MAP[$functionName];
        $subjectArg = self::argument($args, $subjectPosition, $subjectName);
        $constantSubject = null !== $subjectArg && $scope->getType($subjectArg)->isConstantValue()->yes();

        if ('preg_replace_callback_array' === $functionName) {
            return $this->processPregReplaceCallbackArray($patternArg, $scope, $node->getLine(), $functionName, $constantSubject);
        }

        // preg_match($pattern, $subject) alone, its result the only output.
        $plainMatch = $this->checker->optimizationsEnabled() && 'preg_match' === $functionName && 2 === \count($args) && null !== $subjectArg;

        $errors = [];
        foreach ($scope->getType($patternArg)->getConstantStrings() as $constantString) {
            $errors = array_merge(
                $errors,
                $this->checker->check($constantString->getValue(), $node->getLine(), $scope, $this->formatSource($functionName), $constantSubject),
            );

            if ($plainMatch) {
                $errors = array_merge($errors, $this->trivialMatch($constantString->getValue(), $subjectArg, $node->getLine()));
            }
        }

        $replacementArg = 'preg_replace' === $functionName || 'preg_filter' === $functionName ? self::argument($args, 1, 'replacement') : null;
        if (null !== $replacementArg) {
            $errors = array_merge($errors, $this->undefinedReplacementGroups($scope->getType($patternArg), $scope->getType($replacementArg), $node->getLine(), $functionName));
        }

        return $errors;
    }

    public function isOptimizationFormatSafe(string $original, string $optimized): bool
    {
        return $this->checker->isOptimizationFormatSafe($original, $optimized);
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

        return [RuleErrorBuilder::message(\sprintf(
            'preg_match() with %s is %s.',
            PatternChecker::displayPattern($pattern),
            $match->phpExpression((new Standard())->prettyPrintExpr($subject)),
        ))
            ->line($lineNumber)
            ->identifier(self::IDENTIFIER_TRIVIAL_MATCH)
            ->tip('Both say yes for exactly the same subjects, as the automata prove, and the function needs no regex engine; preg_match() returns 1 or 0 where the function returns true or false.')
            ->build()];
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
                        $error = self::undefinedReplacementGroup($reference, $numbering, $pattern, $functionName);
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

                        $builder = RuleErrorBuilder::message($error[0])
                            ->line($lineNumber)
                            ->identifier(self::IDENTIFIER_REPLACEMENT_UNDEFINED_GROUP);
                        if (null !== $error[1]) {
                            $builder = $builder->tip($error[1]);
                        }
                        $errors[$error[0]] = $builder->build();
                    }
                }
            }
        }

        return array_values($errors);
    }

    /**
     * The message and tip for a reference to a group the pattern does not
     * have, null for a reference to one of its groups.
     *
     * @param GroupReference $reference
     *
     * @return array{string, string|null}|null
     */
    private static function undefinedReplacementGroup(array $reference, GroupNumbering $numbering, string $pattern, string $functionName): ?array
    {
        $name = $reference['name'];
        if (null !== $name) {
            $numbers = $numbering->getNamedGroupNumbers($name);

            return [
                \sprintf('Replacement text %s is not a group reference: %s() reads group numbers only and leaves it in the result as written.', $reference['raw'], $functionName),
                [] === $numbers
                    ? \sprintf('%s has no group named "%s" either; refer to a group by its number, as in ${1}.', PatternChecker::displayPattern($pattern), $name)
                    : \sprintf('Write ${%d}, the number of the group "%s".', $numbers[0], $name),
            ];
        }

        $group = (int) $reference['group'];
        $count = $numbering->maxGroupNumber;
        if ($group <= $count) {
            return null;
        }

        $message = \sprintf(
            'Replacement reference %s names group %d, but %s has %s: %s() substitutes an empty string.',
            $reference['raw'],
            $group,
            PatternChecker::displayPattern($pattern),
            match ($count) {
                0 => 'no capturing group',
                1 => '1 capturing group',
                default => $count.' capturing groups',
            },
            $functionName,
        );

        // "$10" is group 10: group 1 followed by a "0" is written "${1}0".
        if ($group >= 10 && intdiv($group, 10) <= $count && !str_starts_with($reference['raw'], '${')) {
            return [$message, \sprintf('Two digits are read after "%1$s": write ${%2$d}%3$d for group %2$d followed by "%3$d".', $reference['raw'][0], intdiv($group, 10), $group % 10)];
        }

        return [$message, null];
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
    private function processPregReplaceCallbackArray(Node $arrayNode, Scope $scope, int $lineNumber, string $functionName, bool $constantSubject): array
    {
        if (!$arrayNode instanceof Array_) {
            return [];
        }

        $errors = [];
        foreach ($arrayNode->items as $item) {
            if (!$item instanceof ArrayItem || !$item->key instanceof String_) {
                continue;
            }

            $pattern = $item->key->value;
            $errors = array_merge(
                $errors,
                $this->checker->check($pattern, $lineNumber, $scope, $this->formatSource($functionName), $constantSubject),
            );
        }

        return $errors;
    }

    private function formatSource(string $functionName): string
    {
        return 'php:'.$functionName.'()';
    }
}
