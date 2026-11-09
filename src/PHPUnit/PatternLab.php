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

namespace PHPRegex\PHPUnit;

use PHPRegex\Parser\Analysis\PatternInfo;
use PHPRegex\Parser\Analysis\PatternInfoAnalyzer;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosProof;

/**
 * The machinery every regex assertion leans on: one lazily created parser
 * held for the process, the no-JIT guarded spelling each match attempt runs
 * under, the capture-expectation vocabulary, and the memoized ReDoS
 * verdicts.
 *
 * The parser is held once because its cache is per instance, so a fresh
 * parser per assertion would parse every pattern again. ReDoS verdicts are
 * memoized per pattern; the analyzer is deterministic per release, so the
 * memoization cannot be observed, only the budget spent twice is saved.
 *
 * @internal the assertions and their constraints are the public doors
 */
final class PatternLab
{
    private static ?RegexParser $parser = null;

    private static ?PcreEngine $engine = null;

    /**
     * @var array<string, string|null> the memoized failure text, a missing key an unrun verdict
     *
     * Uncapped by design: it grows one entry per distinct pattern a suite
     * asserts linearity on (measured at roughly a kilobyte each), so it is
     * bounded by the suite itself — a ceiling, never a leak
     */
    private static array $linearFailures = [];

    /**
     * The export in progress: set by the outermost export() call, read by
     * the calls it makes for an array's keys and values.
     */
    private static bool $exporting = false;

    private static int $exportBudget = 0;

    private static string $exportOutput = '';

    private static bool $exportCut = false;

    /**
     * The parser every assertion reads patterns through, created on the
     * first call and held for the process.
     */
    public static function parser(): RegexParser
    {
        return self::$parser ??= RegexParser::create();
    }

    /**
     * One guarded match attempt of the pattern against the subject, judged
     * against the expectation vocabulary before any value is compared: a
     * pattern that does not parse, an expectation key no group of the
     * pattern answers to, or an engine refusal each answer as a failure
     * text, never as a match result.
     *
     * @param array<int|string, mixed> $expected the capture expectation: true, false, a string value, or [value, byte offset], keyed by group number or name
     */
    public static function run(string $pattern, string $subject, array $expected): MatchAttempt
    {
        try {
            $node = self::parser()->parse($pattern);
        } catch (RegexException $e) {
            return new MatchAttempt(null, [], [], self::libraryFailure($e));
        }

        $expectation = null;
        if ([] !== $expected) {
            try {
                $info = PatternInfoAnalyzer::analyzePattern(self::parser(), $pattern);
            } catch (RegexException $e) {
                return new MatchAttempt(null, [], [], self::libraryFailure($e));
            }

            // The trailing flags carry the whole-pattern n; an inline (?n)
            // only narrows the message wording (the generic unknown-group
            // refusal), never the numbers, which the views already model.
            $judged = self::judge($expected, $info, str_contains($node->flags, 'n'));
            if (\is_string($judged)) {
                return new MatchAttempt(null, [], [], $judged);
            }

            $expectation = $judged;
        }

        $attempt = self::match($pattern, $subject);
        if (false === $attempt['result']) {
            return new MatchAttempt(null, [], [], $attempt['error']);
        }

        if (1 !== $attempt['result']) {
            return new MatchAttempt(false, [], [], null);
        }

        // An empty expectation asks only whether the pattern matched, so
        // nothing is judged and nothing is compared.
        $actual = [];
        if (null !== $expectation) {
            foreach ($expected as $key => $spelling) {
                [$value, $offset] = $attempt['groups'][$expectation->numbers[$key]] ?? [null, -1];
                // Both boolean spellings read the one comparable fact,
                // whether the group participated: 'false' expects it not to.
                $actual[$key] = \is_bool($spelling) ? null !== $value
                    : (\is_string($spelling) ? $value : [$value, $offset]);
            }
        }

        return new MatchAttempt(true, $expected, $actual, null);
    }

    /**
     * Why the running engine refuses the pattern, or null when it compiles
     * it: the truth of assertPatternCompiles() is the engine's alone.
     */
    public static function compileFailure(string $pattern): ?string
    {
        self::$engine ??= new PcreEngine();

        $error = self::$engine->compile($pattern);

        return $error?->message;
    }

    /**
     * Why the pattern cannot be called linear, or null when one match
     * attempt is proven linear and the unanchored search carries no
     * quadratic-cost witness. The first line of a failure carries the
     * verdict's stable prefix: "proven unsafe:" for a held witness or a
     * quadratic search, "could not analyze:" for an unproven one.
     *
     * Memoized per pattern for the process: the analyzer is deterministic
     * per release, so the second call answers what the first did.
     */
    public static function linearFailure(string $pattern): ?string
    {
        if (!\array_key_exists($pattern, self::$linearFailures)) {
            self::$linearFailures[$pattern] = self::verdictFailure((new RedosAnalyzer(self::parser()))->analyze($pattern));
        }

        return self::$linearFailures[$pattern];
    }

    /**
     * A value as the failure messages show it. All children share a work
     * budget and an 8192-byte output ceiling, including the truncation
     * marker. Depth alone cannot bound expansion of shared or cyclic arrays:
     * the outermost call holds the budget and the output, and an array's
     * keys and values are exported by calling it again.
     */
    public static function export(mixed $value, int $depth = 0): string
    {
        $outermost = !self::$exporting;
        if ($outermost) {
            self::$exporting = true;
            self::$exportBudget = 256;
            self::$exportOutput = '';
            self::$exportCut = false;
        }

        try {
            if (self::$exportCut) {
                // Past the ceiling: nothing more is spelled.
            } elseif (self::$exportBudget <= 0) {
                self::$exportCut = true;
            } else {
                self::$exportBudget--;
                if (!\is_array($value)) {
                    self::appendExport(null === $value || \is_scalar($value) ? self::exportScalar($value) : get_debug_type($value));
                } elseif ($depth >= 8) {
                    self::appendExport('[…]');
                } else {
                    self::appendExport('[');
                    $list = array_is_list($value);
                    $first = true;
                    foreach ($value as $key => $part) {
                        if (!$first) {
                            self::appendExport(', ');
                        }
                        $first = false;
                        if (!$list) {
                            // A key is a scalar: no depth to carry.
                            self::export($key);
                            self::appendExport(' => ');
                        }
                        self::export($part, $depth + 1);
                        if (self::$exportCut) {
                            break;
                        }
                    }
                    self::appendExport(']');
                }
            }

            return $outermost ? self::$exportOutput.(self::$exportCut ? '…' : '') : '';
        } finally {
            if ($outermost) {
                self::$exporting = false;
                self::$exportOutput = '';
            }
        }
    }

    private static function exportScalar(bool|int|float|string|null $value): string
    {
        return match (true) {
            null === $value => 'null',
            \is_bool($value) => $value ? 'true' : 'false',
            // Sliced before escaping so a large scalar or key cannot create
            // an unbounded temporary string. This prefix already fills the cap.
            \is_string($value) => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], substr($value, 0, 8192))."'",
            default => (string) $value,
        };
    }

    private static function appendExport(string $part): void
    {
        if (self::$exportCut) {
            return;
        }

        // Reserve three bytes for the UTF-8 ellipsis even on exact fits, and
        // cut between characters.
        $available = 8192 - 3 - \strlen(self::$exportOutput);
        if (\strlen($part) > $available) {
            // Back from a continuation byte (10xxxxxx) to the start of its
            // character, so the cut keeps whole characters. The output never
            // passes the ceiling, so what is available is never negative.
            $end = $available;
            while ($end > 0 && 0x80 === (\ord($part[$end]) & 0xC0)) {
                $end--;
            }
            self::$exportOutput .= substr($part, 0, $end);
            self::$exportCut = true;

            return;
        }
        self::$exportOutput .= $part;
    }

    /**
     * The one guarded match attempt, under PREG_UNMATCHED_AS_NULL and
     * PREG_OFFSET_CAPTURE, so a participating group is [string value, int
     * byte offset] and a non-participating one a null value at offset -1.
     * A pattern the engine answers false for is an error, carried as one.
     *
     * @return array{result: int|false, groups: array<int|string, array{string|null, int}>, error: string|null}
     */
    private static function match(string $pattern, string $subject): array
    {
        self::$engine ??= new PcreEngine();
        $outcome = self::$engine->match($pattern, $subject, null, 0, \PREG_UNMATCHED_AS_NULL | \PREG_OFFSET_CAPTURE);

        if (null === $outcome->matched) {
            return ['result' => false, 'groups' => [], 'error' => $outcome->error];
        }

        /** @var array<int|string, array{string|null, int}> $groups */
        $groups = $outcome->groups;

        return ['result' => true === $outcome->matched ? 1 : 0, 'groups' => $groups, 'error' => null];
    }

    /**
     * The expectation judged against the pattern's own groups: every key
     * resolved to the group number it names, or the refusal that names what
     * the pattern really has.
     *
     * @param array<int|string, mixed> $expected
     *
     * @return Expectation|string the expectation read, or the refusal text
     */
    private static function judge(array $expected, PatternInfo $info, bool $noAutoCapture): Expectation|string
    {
        // Under n the engine keeps the NAMED groups capturing, renumbered
        // from 1, and drops the un-named ones — and these views model
        // exactly that, so names and numbers alike are judged against them.
        $numbers = [];
        foreach ($expected as $key => $spelling) {
            if (\is_int($key) || ('' !== ($digitable = (string) $key) && \strlen($digitable) === strspn($digitable, '0123456789'))) {
                $number = (int) $key;
                if (0 > $number || $number > $info->captureCount) {
                    if ($noAutoCapture) {
                        return sprintf(
                            'the pattern has no group %d under the n modifier; only named groups capture under it, renumbered from 1. Its groups are %s.',
                            $number,
                            self::groupList($info),
                        );
                    }

                    return sprintf('the pattern has no group %d; its groups are %s.', $number, self::groupList($info));
                }

                $numbers[$key] = $number;

                continue;
            }

            $owned = $info->names[$key] ?? null;
            if (null === $owned) {
                return sprintf("the pattern has no group named '%s'; its groups are %s.", $key, self::groupList($info));
            }

            if (\count($owned) > 1) {
                return sprintf(
                    "the group name '%s' owns several group numbers (%s); assert by number instead.",
                    $key,
                    implode(', ', $owned),
                );
            }

            $numbers[$key] = $owned[0];
        }

        foreach ($expected as $key => $spelling) {
            if (true === $spelling || \is_string($spelling)) {
                continue;
            }

            if (false === $spelling) {
                if (0 === $numbers[$key]) {
                    return 'group 0 is the whole match: it participates whenever the pattern matches, so expecting it not to contradicts the match.';
                }

                continue;
            }

            if (\is_array($spelling)
                && 2 === \count($spelling)
                && \array_key_exists(0, $spelling)
                && \array_key_exists(1, $spelling)
                && \is_string($spelling[0])
                && \is_int($spelling[1])
            ) {
                continue;
            }

            return sprintf(
                'unknown expectation for %s: %s. Expected true (participated), false (did not participate), a string value, or [value, byte offset].',
                \is_int($key) ? "group {$key}" : "group '{$key}'",
                self::export($spelling),
            );
        }

        return new Expectation($numbers, $expected);
    }

    /**
     * The pattern's groups as a refusal lists them: the whole match, then
     * every capture group by number, its names beside it when it has any.
     */
    private static function groupList(PatternInfo $info): string
    {
        $names = [];
        foreach ($info->names as $name => $owned) {
            foreach ($owned as $number) {
                $names[$number][] = $name;
            }
        }

        $groups = ['0 (the whole match)'];
        for ($number = 1; $number <= $info->captureCount; $number++) {
            $groups[] = isset($names[$number])
                ? sprintf("%d '%s'", $number, implode('/', $names[$number]))
                : (string) $number;
        }

        return implode(', ', $groups);
    }

    /**
     * The failure text for a library exception: its own message, and the
     * caret snippet it carries when it carries one.
     */
    private static function libraryFailure(RegexException $e): string
    {
        $snippet = $e->snippet ?? '';

        return '' === $snippet ? $e->getMessage() : $e->getMessage()."\n".$snippet;
    }

    /**
     * The failure text for the ReDoS verdict: nothing when one attempt is
     * proven linear with no quadratic search witness, else the verdict in
     * the assertion's own words, led by its stable prefix.
     */
    private static function verdictFailure(RedosAnalysis $verdict): ?string
    {
        if ($verdict->isProvenSafe() && null === $verdict->searchCost) {
            return null;
        }

        if (null !== $verdict->searchCost) {
            return sprintf(
                'proven unsafe: quadratic unanchored search. One match attempt is linear, but an unanchored search retries it at every start of a run of %s, a cost the analyzer holds a degree-%d witness for.',
                self::export($verdict->searchCost->run),
                $verdict->searchCost->degree,
            );
        }

        if (null !== $verdict->witness) {
            return sprintf('proven unsafe: %s. A witness input is %s.', $verdict->headline(), $verdict->witness->render());
        }

        if (null !== $verdict->error) {
            return sprintf('could not analyze: the analyzer returned an error: %s', self::cleanError($verdict->error));
        }

        if (RedosProof::BudgetExceeded === $verdict->proof) {
            return 'could not analyze: the analysis budget was exceeded before any verdict was reached.';
        }

        return sprintf("could not analyze: %s; the pattern sits outside the analyzer's model.", $verdict->headline());
    }

    /**
     * The analyzer's error text without its exception class name, which the
     * verdict carries in front of the message itself.
     */
    private static function cleanError(string $error): string
    {
        $cut = strpos($error, ': ');

        return false === $cut || !str_contains(substr($error, 0, $cut), '\\') ? $error : substr($error, $cut + 2);
    }
}
