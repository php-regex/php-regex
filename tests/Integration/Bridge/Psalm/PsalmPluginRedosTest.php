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

namespace PHPRegex\Tests\Integration\Bridge\Psalm;

use PHPRegex\Linter\RedosVerdict;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Redos\RedosSearchCost;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Exception\ConfigException;

/**
 * With <redos>true</redos>, the plugin reports the ReDoS findings the
 * PHPStan extension reports at a preg_* call, from the same analysis and in
 * the same words: RegexRedos for one attempt at or above <redosThreshold>
 * (high when unset), with the headline of regex.redos; RegexRedosSearch for
 * the search cost, regex.redos.search's, the loop of a function that looks
 * for every match included. A constant subject carries no attack, and the
 * check is off unless asked for.
 */
final class PsalmPluginRedosTest extends TestCase
{
    private const FILE = 'Redos/redos.php';

    /**
     * Each finding at threshold medium: its line, its issue, its headline
     * and the function whose loop the call runs, when it names one.
     */
    private const MEDIUM = [
        [21, 'RegexRedos', 'Exponential backtracking (ReDoS): /^(\w+\s?)+$/', null],
        [22, 'RegexRedos', 'Polynomial backtracking (ReDoS): /\d*\d*\d*$/', null],
        [23, 'RegexRedos', 'Potential backtracking (ReDoS): /^(a+)+\1$/', null],
        [24, 'RegexRedos', 'Polynomial backtracking (ReDoS): /(\d+)(\d+)$/', null],
        [25, 'RegexRedosSearch', 'Quadratic search (ReDoS): /\s+$/', null],
        [26, 'RegexRedosSearch', 'Quadratic search (ReDoS): /(?:a+\d)?/', 'preg_match_all'],
        [27, 'RegexRedosSearch', 'Quadratic search (ReDoS): /(?:a+\d)?/', 'preg_split'],
        [28, 'RegexRedosSearch', 'Quadratic search (ReDoS): /(?:a+\d)?/', 'preg_replace_callback_array'],
        [29, 'RegexRedosSearch', 'Quadratic search (ReDoS): /(?:a+\d)?/', 'preg_replace'],
        [30, 'RegexRedosSearch', 'Quadratic search (ReDoS): /(?:a+\d)?/', 'preg_replace'],
        [37, 'RegexRedos', 'Exponential backtracking (ReDoS): /^(a+)+$/', null],
        [45, 'RegexRedos', 'Exponential backtracking (ReDoS): /^(a+)+$/', null],
        [46, 'RegexRedosSearch', 'Quadratic search (ReDoS): /(?:a+\d)?/', 'preg_replace'],
    ];

    #[Test]
    public function test_plugin_reports_the_redos_findings_of_the_preg_calls(): void
    {
        $exponential = PcreInterpreter::pattern('/^(\w+\s?)+$/');
        $this->assertFalse(@preg_match($exponential, str_repeat('0', 40).'!'), 'Oracle: the attack does not exhaust the backtrack limit.');
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error(), 'Oracle: '.preg_last_error_msg());
        $retry = PcreInterpreter::pattern('/(?:a+\d)?/');
        $this->assertSame(1002, preg_match_all($retry, str_repeat('a', 1000).'!'), 'Oracle: preg_match_all() stops before the end of the run.');
        $this->assertSame(1, preg_match($retry, str_repeat('a', 1000).'!'), 'Oracle: preg_match() goes on.');

        $found = $this->findings(['redos' => 'true', 'redosThreshold' => 'medium']);

        $this->assertSame(
            array_map(static fn (array $row): array => [$row[0], $row[1], $row[2]], self::MEDIUM),
            array_map(static fn (array $row): array => [$row[0], $row[1], $row[2]], $found),
        );
        foreach (self::MEDIUM as $index => [$line, , , $function]) {
            if (null !== $function) {
                $this->assertStringContainsString(RedosVerdict::everyMatchMessage($function, self::retried()), $found[$index][3], 'Line '.$line);
            }
        }
    }

    /**
     * The message holds the headline, then what the PHPStan extension
     * prints in its tip: the severity and how it was reached and the attack,
     * or what the search cost means and its attack.
     */
    #[Test]
    public function test_plugin_words_a_finding_as_the_phpstan_extension_does(): void
    {
        $found = $this->findings(['redos' => 'true', 'redosThreshold' => 'medium']);
        $messages = array_column($found, 3, 0);

        $this->assertSame('Exponential backtracking (ReDoS): /^(\w+\s?)+$/. Severity: critical, exponential (proven). Attack: "0" x n . "!"', $messages[21]);
        $this->assertStringStartsWith('Polynomial backtracking (ReDoS): /\d*\d*\d*$/. Severity: high, polynomial degree 3 (proven). Attack: ', $messages[22]);
        $this->assertSame('Potential backtracking (ReDoS): /^(a+)+\1$/. Severity: critical, heuristic.', $messages[23]);
        $this->assertStringStartsWith("Quadratic search (ReDoS): /\\s+$/. Quadratic search: one attempt is linear (proven); an unanchored search is quadratic in PCRE2's interpreter", $messages[25]);
        $this->assertStringContainsString('Attack: " " x n . "!".', $messages[25]);
        $this->assertStringStartsWith('Quadratic search (ReDoS): /(?:a+\d)?/. '.RedosVerdict::everyMatchMessage('preg_match_all', self::retried()).' ', $messages[26]);
        $this->assertStringContainsString('Attack: "a" x n.', $messages[26]);
        foreach ($messages as $line => $message) {
            $this->assertStringNotContainsString("\n", $message, 'Line '.$line.': one line, as every report of Psalm prints it.');
        }
    }

    /**
     * The issue points at the pattern argument, or at the element of an
     * array of patterns, or at the key of preg_replace_callback_array().
     */
    #[Test]
    public function test_plugin_reports_a_finding_on_its_pattern(): void
    {
        $selected = [];
        foreach ($this->issues(['redos' => 'true', 'redosThreshold' => 'medium']) as [$line, , , $text]) {
            $selected[$line] = $text;
        }

        $this->assertSame("'/^(\\w+\\s?)+$/'", $selected[21] ?? null);
        $this->assertSame("'/(?:a+\\d)?/'", $selected[28] ?? null);
        $this->assertSame("'/(?:a+\\d)?/'", $selected[29] ?? null);
    }

    /**
     * Unset, the threshold is high, as everywhere else: a proven quadratic
     * attempt and a search cost, both medium, are not reported.
     */
    #[Test]
    public function test_plugin_reports_from_the_high_threshold_by_default(): void
    {
        $this->assertSame([21, 22, 23, 37, 45], array_column($this->findings(['redos' => 'true']), 0));
        // The heuristic verdict on line 23 is critical too.
        $this->assertSame([21, 23, 37, 45], array_column($this->findings(['redos' => 'TRUE', 'redosThreshold' => 'Critical']), 0));
    }

    /**
     * The check is off unless <redos> turns it on: the analysis is the
     * expensive one, as in the PHPStan extension.
     */
    #[Test]
    public function test_plugin_reports_no_redos_finding_unless_asked(): void
    {
        $this->assertSame([], $this->findings([]));
        $this->assertSame([], $this->findings(['redos' => 'false', 'redosThreshold' => 'low']));
    }

    #[Test]
    #[DataProvider('provideUnreadableOptions')]
    public function test_plugin_refuses_a_redos_option_it_cannot_read(string $option, string $value, string $message): void
    {
        try {
            PsalmRun::issues([self::FILE], '8.4', [$option => $value]);
            $this->fail(\sprintf('<%s>%s</%s> was accepted.', $option, $value, $option));
        } catch (ConfigException $e) {
            $previous = $e->getPrevious();
            $this->assertInstanceOf(InvalidRegexOptionException::class, $previous, $e->getMessage());
            $this->assertSame($message, $previous->getMessage());
        }
    }

    /**
     * @return iterable<string, array{option: string, value: string, message: string}>
     */
    public static function provideUnreadableOptions(): iterable
    {
        yield 'redos not a boolean' => ['option' => 'redos', 'value' => 'yes', 'message' => 'The <redos> option of PHPRegex\Psalm\Plugin must be true or false; got "yes".'];
        yield 'redosThreshold a verdict' => ['option' => 'redosThreshold', 'value' => 'safe', 'message' => 'The <redosThreshold> option of PHPRegex\Psalm\Plugin must be low, medium, high or critical; got "safe".'];
    }

    /**
     * Each RegexRedos and RegexRedosSearch issue of the fixture: its line,
     * its type, its headline (the message up to the first ". " after the
     * pattern) and its message, in order. Any other issue but a Trace fails.
     *
     * @param array<string, string> $options
     *
     * @return list<array{int, string, string, string}>
     */
    private function findings(array $options): array
    {
        $found = [];
        foreach ($this->issues($options) as [$line, $type, $message]) {
            $this->assertContains($type, ['RegexRedos', 'RegexRedosSearch'], $line.' '.$type.' '.$message);
            $pattern = (string) preg_replace('~^[^:]+: ~', '', $message);
            $end = strpos($pattern, '. ');
            $headline = substr($message, 0, \strlen($message) - \strlen($pattern) + (false === $end ? \strlen($pattern) : $end));
            $found[] = [$line, $type, $headline, $message];
        }

        return $found;
    }

    /**
     * Each issue of the fixture but a Trace: its line, its type, its message
     * and the text it selects, in order.
     *
     * @param array<string, string> $options
     *
     * @return list<array{int, string, string, string}>
     */
    private function issues(array $options): array
    {
        return PsalmRun::reported(PsalmRun::inFile(PsalmRun::issues([self::FILE], '8.4', $options), self::FILE));
    }

    /**
     * The every-match cost of a pattern whose empty match PHP tries again.
     */
    private static function retried(): RedosSearchCost
    {
        return new RedosSearchCost(2, '', 'a', '', false);
    }
}
