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
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPRegex\Linter\Exception\UnsupportedTargetException;
use PHPRegex\Linter\Extraction\PatternFunction;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Text\Ascii;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\IssueBuffer;
use Psalm\NodeTypeProvider;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TObject;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TTrue;
use Psalm\Type\Union;

/**
 * Reads every call of a static method of a wrapper library once Psalm
 * analysed it: composer/pcre's Preg::match(), nette/utils'
 * Strings::replace(), spatie/regex's Regex::matchAll(), Laravel's
 * Str::isMatch() and the others of the presets `regex lint` and the PHPStan
 * extension read (PatternFunctionRegistry::defaults()), each matched on its
 * fully qualified class.
 *
 * It reports the constant pattern the targeted PHP and PCRE2 refuse, at the
 * argument the library takes it, and, when asked, the ReDoS findings of one
 * they accept, as PregCallAnalyzer does for the preg_* function the method
 * runs: the pattern of a literal or of a name that holds one, each pattern
 * of an array written at the call, the keys where the library reads them.
 * The loop of a method that looks for every match is the one of its preg
 * function, unless a limit the call gives bounds it, as its type says. The
 * subject is not told apart, so a constant one does not quiet a finding;
 * nothing is typed: what a wrapper returns is its library's to say.
 *
 * Psalm analyses no argument of a call on a class it does not know, and
 * hands no such call here: it reports the class instead.
 *
 * @internal
 */
final class WrapperCallAnalyzer implements AfterExpressionAnalysisInterface
{
    private static ?PatternFunctionRegistry $registry = null;

    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $call = $event->getExpr();
        if (!$call instanceof StaticCall || !$call->class instanceof Name || !$call->name instanceof Identifier || $call->isFirstClassCallable()) {
            return null;
        }

        $class = self::calledClass($call->class, $event->getContext());
        $function = null === $class ? null : (self::$registry ??= PatternFunctionRegistry::defaults())->lookupMethod($class, $call->name->toString());
        if (null === $function) {
            return null;
        }

        try {
            self::analyse($event, $call, $function);
        } catch (UnsupportedTargetException $e) {
            // A target on PCRE1 is no call the library cannot read: the
            // run stops on it, as regex lint does.
            throw $e;
        } catch (ExceptionInterface) {
            // The library could not read the call: nothing is said of it.
        }

        return null;
    }

    /**
     * The class a static call names, fully qualified: Psalm resolved the
     * name against the imports of the file; self:: and static:: name the
     * class the call is written in, parent:: the one it extends.
     */
    private static function calledClass(Name $class, Context $context): ?string
    {
        if ($class->isSpecialClassName()) {
            return 'parent' === $class->toLowerString() ? $context->parent : $context->self;
        }

        $resolved = $class->getAttribute('resolvedName');

        return \is_string($resolved) ? $resolved : $class->toString();
    }

    private static function analyse(AfterExpressionAnalysisEvent $event, StaticCall $call, PatternFunction $function): void
    {
        $source = $event->getStatementsSource();
        $codebase = $event->getCodebase();
        $nodeTypes = $source->getNodeTypeProvider();
        $arguments = array_values($call->getArgs());
        $patterns = [];
        foreach ($function->eachArgument() as $carrier) {
            array_push($patterns, ...self::constantPatterns($carrier, $arguments, $nodeTypes));
        }

        if ([] === $patterns) {
            return;
        }

        $parser = CallTarget::parser($codebase);
        // A guard on PHP_VERSION_ID and a "@regex-target" marker narrow
        // the versions the call is judged for, as for a preg_* call.
        $judgedBy = CallTarget::judgedBy($codebase, $source->getFilePath(), $call->getStartLine(), $call->getStartFilePos());
        $everyMatch = self::everyMatch($function, $arguments, $nodeTypes);
        foreach ($patterns as [$pattern, $node]) {
            $error = PatternVerdicts::firstError($judgedBy, $pattern);
            if (null !== $error) {
                IssueBuffer::maybeAdd(new InvalidRegexPattern($error, new CodeLocation($source, $node)), $source->getSuppressedIssues());
            } elseif ([] !== $judgedBy) {
                // Judged for the lowest version the call runs on.
                RedosFindings::reportLoop($everyMatch, $pattern, $node, $judgedBy[0], $parser, $source);
            }
        }
    }

    /**
     * Each constant pattern the call gives the library at one of its
     * pattern arguments, as the library runs it, with the node an issue
     * points at: the argument when its type is one literal string, else
     * each item of an array written there, its keys where the library
     * reads them. None when what nette/utils appends to its patterns is
     * not known.
     *
     * @param list<Arg> $arguments
     *
     * @return list<array{string, Expr}>
     */
    private static function constantPatterns(PatternFunction $carrier, array $arguments, NodeTypeProvider $nodeTypes): array
    {
        $argument = self::patternArgument($carrier, $arguments);
        $suffix = null === $argument ? null : self::suffix($carrier, $arguments, $nodeTypes);
        if (null === $argument || null === $suffix) {
            return [];
        }

        $pattern = CallArguments::literal($argument, $nodeTypes);
        if (null !== $pattern) {
            // A map of callbacks is no pattern, whatever its type says.
            return $carrier->keysArePatterns && null === $carrier->replacementIndex ? [] : [[$pattern.$suffix, $argument]];
        }

        $keys = $argument instanceof Array_ ? self::readsKeys($carrier, $argument, $arguments, $nodeTypes) : null;
        if (!$argument instanceof Array_ || null === $keys) {
            return [];
        }

        $patterns = [];
        foreach ($argument->items as $item) {
            $node = $item instanceof ArrayItem && !$item->unpack ? ($keys ? $item->key : $item->value) : null;
            $pattern = null === $node ? null : CallArguments::literal($node, $nodeTypes);
            if (null !== $node && null !== $pattern) {
                $patterns[] = [$pattern.$suffix, $node];
            }
        }

        return $patterns;
    }

    /**
     * The pattern argument of a wrapper: by the name of its pattern
     * parameter, case included as PHP compares it, when the call names its
     * arguments, by position otherwise; null when a spread hides it.
     *
     * @param list<Arg> $arguments
     */
    private static function patternArgument(PatternFunction $carrier, array $arguments): ?Expr
    {
        foreach ($arguments as $argument) {
            if (null !== $argument->name && \in_array($argument->name->toString(), $carrier->parameterNames, true)) {
                return $argument->value;
            }
        }

        $given = self::given($arguments, $carrier->argumentIndex, '');

        return $given instanceof Expr ? $given : null;
    }

    /**
     * The argument at a position or under a name: false when the call
     * leaves it out, null when a spread may hold it.
     *
     * @param list<Arg> $arguments
     */
    private static function given(array $arguments, int $position, string $name): Expr|false|null
    {
        $value = false;
        foreach ($arguments as $index => $argument) {
            if ($argument->unpack) {
                return $value instanceof Expr ? $value : null;
            }

            if (null === $argument->name ? $index === $position : $name === $argument->name->toString()) {
                $value = $argument->value;
            }
        }

        return $value;
    }

    /**
     * What the library appends to each pattern of the call: "u" under
     * nette/utils' utf8: true, nothing when the argument is false or left
     * out; null when its type is neither, and the flags of the pattern are
     * not known.
     *
     * @param list<Arg> $arguments
     */
    private static function suffix(PatternFunction $carrier, array $arguments, NodeTypeProvider $nodeTypes): ?string
    {
        if (null === $carrier->utf8Argument) {
            return '';
        }

        $utf8 = self::given($arguments, ...$carrier->utf8Argument);
        if (false === $utf8) {
            return '';
        }

        $type = null === $utf8 ? null : $nodeTypes->getType($utf8);

        return match (true) {
            true === $type?->isTrue() => 'u',
            true === $type?->isFalse() => '',
            default => null,
        };
    }

    /**
     * Whether the library reads its patterns from the keys of an array
     * written at the call, rather than from its values: always for a map
     * of callbacks; for nette/utils' Strings::replace(), when the
     * replacement is a string, or left out, and the first key of the array
     * is a string, as PHP stores it. Null when the code does not say: a
     * replacement that may be a string or not, a first key that is no
     * literal, a spread.
     *
     * @param list<Arg> $arguments
     */
    private static function readsKeys(PatternFunction $carrier, Array_ $array, array $arguments, NodeTypeProvider $nodeTypes): ?bool
    {
        if (!$carrier->keysArePatterns || null === $carrier->replacementIndex) {
            return $carrier->keysArePatterns;
        }

        $replacement = self::given($arguments, $carrier->replacementIndex, PatternFunction::REPLACEMENT_PARAMETER_NAMES[0]);
        $type = $replacement instanceof Expr ? $nodeTypes->getType($replacement) : null;
        $callable = false === $replacement ? false : (null === $type ? null : self::isNoString($type));
        if (true === $callable) {
            return false;
        }

        $first = $array->items[0] ?? null;
        if (null === $callable || !$first instanceof ArrayItem || $first->unpack) {
            return null;
        }

        if (null === $first->key) {
            return false;
        }

        $key = $nodeTypes->getType($first->key);
        if (true === $key?->isSingleIntLiteral()) {
            return false;
        }

        return true === $key?->isSingleStringLiteral() ? PatternFunction::isStringKey($key->getSingleStringLiteral()->value) : null;
    }

    /**
     * Whether each value of the type is an object or an array, as
     * nette/utils tests its replacement, false when each is a string, null
     * when the type says neither.
     */
    private static function isNoString(Union $type): ?bool
    {
        $strings = 0;
        $others = 0;
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TString) {
                $strings++;
            } elseif ($atomic instanceof TNamedObject || $atomic instanceof TObject || $atomic instanceof TArray || $atomic instanceof TKeyedArray) {
                $others++;
            } else {
                return null;
            }
        }

        return 0 === $strings ? true : (0 === $others ? false : null);
    }

    /**
     * The preg function whose loop over every match the call runs, which
     * PHP retries after an empty match, as the PHPStan extension reads it:
     * null for a call that stops at the first match, one an argument tells
     * to, as its type says, or one whose limit bounds how many matches it
     * makes.
     *
     * @param list<Arg> $arguments
     */
    private static function everyMatch(PatternFunction $function, array $arguments, NodeTypeProvider $nodeTypes): ?string
    {
        if (null !== $function->loopArgument) {
            $function = self::decided($function, $function->loopArgument[0], $function->loopArgument[1], $arguments, $nodeTypes);
        }

        // A closure as replacement makes the call run preg_replace_callback().
        if (null !== $function->callbackArgument) {
            $replacement = self::given($arguments, ...$function->callbackArgument);
            $type = $replacement instanceof Expr ? $nodeTypes->getType($replacement) : null;
            if (null !== $type && self::isClosure($type)) {
                $function = $function->replacingThroughCallback();
            }
        }

        if (null === $function->everyMatch || null === $function->limitArgument) {
            return $function->everyMatch;
        }

        $limit = self::given($arguments, ...$function->limitArgument);
        $type = $limit instanceof Expr ? $nodeTypes->getType($limit) : null;
        if (null === $type) {
            return $function->everyMatch;
        }

        foreach ($type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TLiteralInt || !$function->boundedBy($atomic->value)) {
                return $function->everyMatch;
            }
        }

        return null;
    }

    /**
     * The function as the argument that decides whether it looks for
     * every match leaves it, nette/utils' lazy for one: left out, as it
     * is; given, the loop its value makes it run, as its type says, and
     * none when the type allows a value that makes it run none, or one
     * that is no boolean.
     *
     * @param list<Arg> $arguments
     */
    private static function decided(PatternFunction $function, int $position, string $name, array $arguments, NodeTypeProvider $nodeTypes): PatternFunction
    {
        $argument = self::given($arguments, $position, $name);
        if (false === $argument) {
            return $function;
        }

        $decided = $function->decidedBy(null);
        foreach ($argument instanceof Expr ? $nodeTypes->getType($argument)?->getAtomicTypes() ?? [] : [] as $atomic) {
            $decided = $function->decidedBy(match (true) {
                $atomic instanceof TTrue => 1,
                $atomic instanceof TFalse => 0,
                default => null,
            });
            if (null === $decided->everyMatch) {
                return $decided;
            }
        }

        return $decided;
    }

    /**
     * Whether each value of the type is a closure.
     */
    private static function isClosure(Union $type): bool
    {
        foreach ($type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject || 'closure' !== Ascii::lower($atomic->value)) {
                return false;
            }
        }

        return true;
    }
}
