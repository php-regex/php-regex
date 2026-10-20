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
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PHPRegex\Linter\Config\UnsupportedTargetException;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\RegexParser;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\IssueBuffer;
use Psalm\NodeTypeProvider;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Union;

/**
 * Reads every call of a global preg_* function once Psalm analysed it.
 *
 * It reports the constant pattern the targeted PHP and PCRE2 refuse. For a
 * pattern they accept and the running engine compiles, it types $matches:
 * where preg_match() returned 1, the pattern's shape, where it did not, []
 * (no match writes [], and so does a match error), unless the call is
 * compared with something; after preg_match_all(), its shape, for a pattern
 * without \K and an offset absent or a constant <= 0. Where preg_match()
 * returned 1 and $matches is not typed, a string subject is narrowed to what
 * the pattern can match (SubjectType). preg_quote() of literals is typed as
 * the literal it returns; around preg_quote() of a text not known,
 * preg_match() types $matches from the rest of the pattern (QuotedPattern).
 * preg_split() under PREG_SPLIT_NO_EMPTY or PREG_SPLIT_OFFSET_CAPTURE
 * returns what the flags say; the callback of preg_replace_callback() is
 * typed by CallbackParameters. Everything else is left to Psalm's stubs,
 * whose taint flows, purity and parameter checks all stay: a type the plugin
 * narrows keeps the data flow of the stub's.
 *
 * CallArguments binds the arguments, CallTarget reads the versions the call
 * is judged for, PatternVerdicts what the library says of the pattern;
 * CallTypes writes the types and CallAssertions what holds on the call's
 * result, through Psalm's internal node data, reached through
 * PsalmInternals: should it move, the calls are left to the stubs.
 *
 * @internal
 *
 * @phpstan-import-type PatternVerdict from PatternVerdicts
 */
final class PregCallAnalyzer implements AfterExpressionAnalysisInterface
{
    /**
     * The first PHP that reads the n modifier.
     */
    private const NO_AUTO_CAPTURE_FLOOR = 80200;

    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $call = $event->getExpr();
        $source = $event->getStatementsSource();
        if ($call instanceof BinaryOp && \in_array($call::class, CallAssertions::COMPARISONS, true)) {
            CallAssertions::dropAssertions($call, $source);

            return null;
        }

        if (!$call instanceof FuncCall || !$call->name instanceof Name || $call->isFirstClassCallable()) {
            return null;
        }

        $function = CallArguments::globalFunction($call->name, $source);
        $arguments = null === $function ? null : CallArguments::bind(array_values($call->getArgs()), CallArguments::PARAMETERS[$function]);
        if ('preg_quote' === $function && null !== $arguments) {
            CallTypes::quote($call, $arguments, $source->getNodeTypeProvider());

            return null;
        }

        if (null === $function || null === $arguments || !isset($arguments['pattern'])) {
            return null;
        }

        try {
            self::analyse($event, $call, $function, $arguments);
        } catch (UnsupportedTargetException $e) {
            // A target on PCRE1 is no call the library cannot read: the
            // run stops on it, as regex lint does.
            throw $e;
        } catch (ExceptionInterface) {
            // The library could not read the call: Psalm's stub decides.
        }

        return null;
    }

    /**
     * What the callback of a preg_replace_callback() call receives on each
     * match: what preg_match() writes for the call's pattern and flags, a
     * pattern built around preg_quote() of a text not known included, which
     * matches only where it compiles. Null when the plugin cannot read the
     * call, which leaves the callback to Psalm's stub.
     *
     * Psalm asks for the parameters before it analyses the arguments: an
     * argument is analysed here when it is a literal, a constant, a variable,
     * or a concatenation or an | of those and of preg_quote() calls, none of
     * which writes anything.
     *
     * @internal for CallbackParameters
     *
     * @param list<Arg> $args
     */
    public static function callbackMatchesType(array $args, StatementsSource $source, Context $context): ?Union
    {
        $codebase = $source->getCodebase();
        $arguments = CallArguments::bind($args, CallArguments::PARAMETERS['preg_replace_callback']);
        $nodeTypes = $source->getNodeTypeProvider();
        if (null === $arguments || !isset($arguments['pattern']) || !CallArguments::analysedInPlace($arguments['pattern'], $source, $context) || (isset($arguments['flags']) && !CallArguments::analysedInPlace($arguments['flags'], $source, $context))) {
            return null;
        }

        $parser = CallTarget::parser($codebase);

        try {
            $pattern = CallArguments::literal($arguments['pattern'], $nodeTypes) ?? self::patternAroundQuotes($arguments['pattern'], $source, $parser);
            $verdict = null === $pattern ? null : PatternVerdicts::verdict($parser, $pattern);
            if (null === $pattern || null === $verdict || null !== $verdict['error'] || ($verdict['noAutoCapture'] && $codebase->analysis_php_version_id < self::NO_AUTO_CAPTURE_FLOOR)) {
                return null;
            }

            return CallTypes::matchesType($parser, $pattern, $verdict, 'preg_replace_callback', $arguments, $nodeTypes, $codebase);
        } catch (ExceptionInterface) {
            return null; // The library could not read the call: Psalm's stub decides.
        }
    }

    /**
     * @param array<string, Expr> $arguments the argument bound to each parameter, by name
     */
    private static function analyse(AfterExpressionAnalysisEvent $event, FuncCall $call, string $function, array $arguments): void
    {
        $source = $event->getStatementsSource();
        $codebase = $event->getCodebase();
        $nodeTypes = $source->getNodeTypeProvider();
        $parser = CallTarget::parser($codebase);
        // A guard on PHP_VERSION_ID and a "@regex-target" marker narrow
        // the versions the call is judged for; the types stay those of
        // Psalm's PHP.
        $judgedBy = CallTarget::judgedBy($parser, $codebase, $source->getFilePath(), $call->getStartLine(), $call->getStartFilePos());

        $judged = null;
        foreach (CallArguments::constantPatterns($function, $arguments['pattern'], $nodeTypes) as [$pattern, $node]) {
            $verdict = PatternVerdicts::verdict($parser, $pattern);
            $judged = [$pattern, $verdict];
            $error = null === $judgedBy ? null : ($judgedBy === $parser ? $verdict : PatternVerdicts::verdict($judgedBy, $pattern))['error'];
            if (null !== $error) {
                IssueBuffer::maybeAdd(new InvalidRegexPattern($error, new CodeLocation($source, $node)), $source->getSuppressedIssues());
            }
        }

        if ('preg_split' === $function) {
            CallTypes::splitType($call, $arguments, $nodeTypes);
        }

        if (null === $judged) {
            // A pattern built around preg_quote() of a text not known types $matches from its groups.
            $quoted = 'preg_match' === $function ? self::patternAroundQuotes($arguments['pattern'], $source, $parser) : null;
            if (null !== $quoted) {
                self::typeAroundQuotes($call, $quoted, $arguments, $nodeTypes, $parser, $codebase);
            }

            return;
        }

        [$pattern, $verdict] = $judged;
        // PHP refuses the n modifier below 8.2: such a pattern does not compile there.
        if (null === $verdict['shape'] || ($verdict['noAutoCapture'] && $codebase->analysis_php_version_id < self::NO_AUTO_CAPTURE_FLOOR)) {
            return;
        }

        $matches = $arguments['matches'] ?? null;
        $type = $matches instanceof Variable && \is_string($matches->name) ? CallTypes::matchesType($parser, $pattern, $verdict, $function, $arguments, $nodeTypes, $codebase) : null;
        if ('preg_match_all' === $function) {
            if (null !== $type && $matches instanceof Variable && \is_string($matches->name)) {
                // Psalm assigned the stub's type to the variable: the shape replaces it, with its data flow.
                $context = $event->getContext();
                $current = $context->vars_in_scope['$'.$matches->name] ?? null;
                if (null !== $current) {
                    $context->vars_in_scope['$'.$matches->name] = $type->setParentNodes($current->parent_nodes);
                }

                // Where it returned a count of 1 or more, no list is empty.
                $matched = CallTypes::matchesType($parser, $pattern, $verdict, $function, $arguments, $nodeTypes, $codebase, true);
                if (null !== $matched && $matched->isSingle()) {
                    CallAssertions::assertOnResult($nodeTypes, $call, [CallAssertions::assertion($call, $matches, $matched->getSingleAtomic())], []);
                }
            }

            return;
        }

        if ('preg_match' !== $function) {
            return;
        }

        // Psalm reads a false condition as the negation of what holds where it
        // is true: on two variables that negation says nothing of either, and
        // $matches would lose its [] where nothing matched. A call that types
        // $matches leaves its subject as it is.
        if (null !== $type && null !== $matches && $type->isSingle()) {
            CallAssertions::assertOnResult($nodeTypes, $call, [CallAssertions::assertion($call, $matches, $type->getSingleAtomic())], [CallAssertions::assertion($call, $matches, Type::getEmptyArrayAtomic())]);

            return;
        }

        $subject = CallTypes::subjectType($pattern, $verdict, $arguments, $nodeTypes, $parser, $codebase);
        if (null !== $subject && isset($arguments['subject'])) {
            CallAssertions::assertOnResult($nodeTypes, $call, [CallAssertions::assertion($call, $arguments['subject'], $subject)], []);
        }
    }

    /**
     * The pattern standing for a concatenation of literals and preg_quote()
     * calls (QuotedPattern), or null for any other expression.
     */
    private static function patternAroundQuotes(Expr $argument, StatementsSource $source, RegexParser $parser): ?string
    {
        $nodeTypes = $source->getNodeTypeProvider();
        $parts = [];
        $pending = [$argument];
        while ([] !== $pending) {
            $part = array_shift($pending);
            $text = CallArguments::literal($part, $nodeTypes);
            if (null !== $text) {
                $parts[] = $text;
            } elseif ($part instanceof Concat) {
                array_unshift($pending, $part->left, $part->right);
            } elseif ($part instanceof FuncCall && $part->name instanceof Name && !$part->isFirstClassCallable() && 'preg_quote' === CallArguments::globalFunction($part->name, $source)) {
                $parts[] = null;
            } else {
                return null;
            }
        }

        return $argument instanceof Concat && [] !== $parts ? QuotedPattern::of($parts, $parser) : null;
    }

    /**
     * Where preg_match() returned 1, $matches is the shape of the pattern
     * standing for the quoted texts. Where it did not, $matches is left as
     * it is: a quoted text holding the delimiter makes the pattern fail to
     * compile, and the call then leaves $matches as it was.
     *
     * @param array<string, Expr> $arguments
     */
    private static function typeAroundQuotes(FuncCall $call, string $pattern, array $arguments, NodeTypeProvider $nodeTypes, RegexParser $parser, Codebase $codebase): void
    {
        $verdict = PatternVerdicts::verdict($parser, $pattern);
        $matches = $arguments['matches'] ?? null;
        // Never reported: the pattern stands for the groups, not for what the call compiles.
        if (null !== $verdict['error'] || null === $verdict['shape'] || ($verdict['noAutoCapture'] && $codebase->analysis_php_version_id < self::NO_AUTO_CAPTURE_FLOOR) || !$matches instanceof Variable) {
            return;
        }

        $type = CallTypes::matchesType($parser, $pattern, $verdict, 'preg_match', $arguments, $nodeTypes, $codebase);
        if (null !== $type && $type->isSingle()) {
            CallAssertions::assertOnResult($nodeTypes, $call, [CallAssertions::assertion($call, $matches, $type->getSingleAtomic())], []);
        }
    }
}
