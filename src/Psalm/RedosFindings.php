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
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Extraction\PregFunctions;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\RedosFinding;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Text\Ascii;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\NodeTypeProvider;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Union;

/**
 * The ReDoS findings of a preg_* call, as the PHPStan extension reports
 * them under checks.redos: a verdict on one attempt at or above the
 * threshold as RegexRedos, a search cost as RegexRedosSearch, the loop of a
 * function that looks for every match included, each worded as the
 * extension words it, its headline then the details of its tip. The
 * analysis is regex lint's, theoretical, read once per process for a
 * pattern, the lowest version judged and the loop of the call.
 *
 * Off unless <redos> turns it on. A call whose subject is constant, a
 * literal or an array of literals, carries no attack and is not reported,
 * and neither is a pattern the running engine does not compile.
 *
 * @internal
 */
final class RedosFindings
{
    private const ISSUE_ID_REDOS = 'regex.lint.redos';

    private const ISSUE_ID_SEARCH = 'regex.lint.redos.search';

    private static bool $enabled = false;

    /**
     * The lowest severity reported.
     */
    private static string $threshold = 'high';

    /**
     * The analysis of each version a pattern is judged for, by its key.
     *
     * @var array<string, AnalysisService>
     */
    private static array $services = [];

    /**
     * The findings of a pattern, whether each is a search cost and its
     * message, by version, threshold, loop and pattern.
     *
     * @var array<string, list<array{bool, string}>>
     */
    private static array $findings = [];

    /**
     * The plugin's <redos> and <redosThreshold>, null when not given: off,
     * and high.
     *
     * @throws InvalidRegexOptionException when one cannot be read
     */
    public static function configure(?string $redos, ?string $threshold): void
    {
        $enabled = null === $redos ? false : ['true' => true, 'false' => false][Ascii::lower($redos)] ?? null;
        if (null === $enabled) {
            throw new InvalidRegexOptionException(\sprintf('The <redos> option of %s must be true or false; got "%s".', Plugin::class, $redos));
        }

        try {
            self::$threshold = null === $threshold ? 'high' : RedosFinding::threshold($threshold);
        } catch (InvalidRegexOptionException $e) {
            throw new InvalidRegexOptionException(\sprintf('The <redosThreshold> option of %s must be low, medium, high or critical; got "%s".', Plugin::class, $threshold), 0, $e);
        }

        self::$enabled = $enabled;
    }

    /**
     * Reports the findings of one constant pattern of a call on the node it
     * stands at, judged for the lowest version the call is judged for.
     *
     * @param array<string, Expr> $arguments the argument bound to each parameter, by name
     * @param RegexParser         $target    the parser of the target, whose repairs the details offer
     */
    public static function report(string $function, array $arguments, string $pattern, Expr $node, RegexParser $floor, RegexParser $target, StatementsSource $source): void
    {
        $nodeTypes = $source->getNodeTypeProvider();
        if (!self::$enabled || self::constantSubject($function, $arguments, $nodeTypes) || null !== (new PcreEngine())->compile($pattern)) {
            return;
        }

        $everyMatch = self::everyMatch($function, $arguments, $nodeTypes);
        $key = CallTarget::key($floor)."\0".self::$threshold."\0".($everyMatch ?? '')."\0".$pattern;
        foreach (self::$findings[$key] ??= self::findings($pattern, $everyMatch, $floor, $target) as [$search, $message]) {
            $location = new CodeLocation($source, $node);
            IssueBuffer::maybeAdd($search ? new RegexRedosSearch($message, $location) : new RegexRedos($message, $location), $source->getSuppressedIssues());
        }
    }

    /**
     * @return list<array{bool, string}>
     */
    private static function findings(string $pattern, ?string $everyMatch, RegexParser $floor, RegexParser $target): array
    {
        $service = self::$services[CallTarget::key($floor)."\0".self::$threshold] ??= new AnalysisService($floor, redosThreshold: self::$threshold, redosEnabled: true, lintEnabled: false);
        $findings = [];
        foreach ($service->lint([new PatternOccurrence($pattern, '', 0, 'php', everyMatch: $everyMatch)]) as $issue) {
            // The other issues: a pattern the library cannot read, or its
            // complexity.
            $analysis = $issue->analysis;
            if (self::ISSUE_ID_SEARCH === $issue->issueId) {
                $findings[] = [true, RedosFinding::searchHeadline($pattern).'. '.implode(' ', RedosFinding::searchDetails($issue->message, $issue->hint))];
            } elseif (self::ISSUE_ID_REDOS === $issue->issueId && null !== $analysis) {
                $findings[] = [false, RedosFinding::headline($pattern, $analysis).'. '.implode(' ', RedosFinding::details($analysis, RedosFinding::repair($target, $pattern)))];
            }
        }

        return $findings;
    }

    /**
     * The function whose loop over every match the call runs, which PHP
     * retries after an empty match; null for one that stops at the first
     * match, or whose limit, as its type says, bounds how many matches it
     * makes.
     *
     * @param array<string, Expr> $arguments
     */
    private static function everyMatch(string $function, array $arguments, NodeTypeProvider $nodeTypes): ?string
    {
        if (!\in_array($function, PregFunctions::EVERY_MATCH, true)) {
            return null;
        }

        $limit = isset($arguments['limit']) ? $nodeTypes->getType($arguments['limit']) : null;
        $bounded = null !== $limit;
        foreach ($limit?->getAtomicTypes() ?? [] as $atomic) {
            $bounded = $bounded && $atomic instanceof TLiteralInt && PregFunctions::bounds($function, $atomic->value);
        }

        return $bounded ? null : $function;
    }

    /**
     * Whether what the pattern runs on is constant: the call then
     * backtracks the same way on every run, or never.
     *
     * @param array<string, Expr> $arguments
     */
    private static function constantSubject(string $function, array $arguments, NodeTypeProvider $nodeTypes): bool
    {
        $name = PregFunctions::subject($function)[1] ?? '';
        $type = isset($arguments[$name]) ? $nodeTypes->getType($arguments[$name]) : null;

        return null !== $type && self::constant($type);
    }

    /**
     * Whether each value of the type is a literal string, or an array of
     * them whose keys are all known. A subject of another type is no string
     * under strict types, and is read as one that may vary.
     */
    private static function constant(Union $type): bool
    {
        foreach ($type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TKeyedArray) {
                if (!$atomic instanceof TLiteralString) {
                    return false;
                }

                continue;
            }

            if (null !== $atomic->fallback_params) {
                return false;
            }

            foreach ($atomic->properties as $property) {
                if (!self::constant($property)) {
                    return false;
                }
            }
        }

        return true;
    }
}
