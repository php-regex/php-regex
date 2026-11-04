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

namespace PHPRegex\Tests\Documentation;

use PHPRegex\Parser\DelimitedPattern;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Tests\Support\EngineProbe;
use PHPRegex\Tests\Support\PlaygroundDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The driver's trace-input op: the one place PHP's delimiter rules are read
 * for the step debugger. The page hands the trace worker what this op
 * answers — the body, the modifiers, where the body sits in the pattern —
 * and never re-reads a delimiter itself. The subject is cut for the trace
 * at a smaller cap than the page's, on a character boundary, and a pattern
 * with an explicit callout is refused with its input still answered.
 * Every row runs on the tree's driver over src/ and on the bundled driver.
 *
 * @phpstan-type TraceInput array{body: string, modifiers: string, bodyStart: int, bodyEnd: int, subject: string, subjectBytes: int, subjectTruncated: bool}
 * @phpstan-type TraceInputAnswer array{protocol: int, notices: list<string>, input: TraceInput|null, refusal: ?string, error: array{source: string, class: ?string, message: string, position: ?int, code: ?string, hint: ?string}|null, timing: array{traceInputMs: float}}
 */
#[Group('documentation')]
final class PlaygroundTraceInputTest extends TestCase
{
    /**
     * The trace reads this many subject bytes at most: smaller than the
     * page's 10 000 so the step list stays readable.
     */
    private const SUBJECT_CAP = 1000;

    /**
     * The page's pattern cap, delimiters and modifiers included.
     */
    private const PATTERN_CAP = 2000;

    /**
     * @return iterable<string, array{driver: string, root: string}>
     */
    public static function provideDrivers(): iterable
    {
        yield from PlaygroundDriver::drivers();
    }

    /**
     * The four Try examples of the playground (docs/_includes/playground.html)
     * and the delimiter spellings PHP reads: leading whitespace skipped, a
     * bracket pair, a non-slash delimiter.
     *
     * @return iterable<string, array{regex: string, subject: string, body: string, modifiers: string, bodyStart: int}>
     */
    public static function provideDelimitedPatterns(): iterable
    {
        yield 'Try: named groups' => ['regex' => '/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})$/m', 'subject' => "2026-11-02\n2026-11-19\nnot a date", 'body' => '^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})$', 'modifiers' => 'm', 'bodyStart' => 1];
        yield 'Try: e-mail addresses' => ['regex' => '/[\w.+-]+@[\w-]+\.[\w.-]+/i', 'subject' => 'Write to hello@php-regex.com or to noreply@example.org.', 'body' => '[\w.+-]+@[\w-]+\.[\w.-]+', 'modifiers' => 'i', 'bodyStart' => 1];
        yield 'Try: catastrophic backtracking' => ['regex' => '/^(a+)+$/', 'subject' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaab', 'body' => '^(a+)+$', 'modifiers' => '', 'bodyStart' => 1];
        yield 'Try: Unicode properties' => ['regex' => '/\p{Lu}\p{Ll}+/u', 'subject' => 'Ünïcode Straße Ωmega', 'body' => '\p{Lu}\p{Ll}+', 'modifiers' => 'u', 'bodyStart' => 1];
        yield 'leading whitespace before the delimiter' => ['regex' => '  /a b/x', 'subject' => 'ab', 'body' => 'a b', 'modifiers' => 'x', 'bodyStart' => 3];
        yield 'bracket delimiter' => ['regex' => '{a{1,2}}i', 'subject' => 'A', 'body' => 'a{1,2}', 'modifiers' => 'i', 'bodyStart' => 1];
        yield 'hash delimiter' => ['regex' => '#a#', 'subject' => 'a', 'body' => 'a', 'modifiers' => '', 'bodyStart' => 1];
    }

    /**
     * @return iterable<string, array{driver: string, root: string, regex: string, subject: string, body: string, modifiers: string, bodyStart: int}>
     */
    public static function provideDelimitedPatternsOnDrivers(): iterable
    {
        foreach (PlaygroundDriver::drivers() as $driverLabel => $driver) {
            foreach (self::provideDelimitedPatterns() as $label => $row) {
                yield $label.' on the '.$driverLabel => $driver + $row;
            }
        }
    }

    #[Test]
    #[DataProvider('provideDelimitedPatternsOnDrivers')]
    public function test_trace_input_returns_body_modifiers_and_offsets(string $driver, string $root, string $regex, string $subject, string $body, string $modifiers, int $bodyStart): void
    {
        // The engine reads the same pattern: what the driver splits is what
        // preg_match() compiles, and the library's own delimiter parse is the
        // reference the X-Ray already relies on.
        // Probed on an empty subject: the catastrophic example exhausts the
        // backtrack limit on its own subject, which is a match outcome, not
        // a compile failure.
        $this->assertNotFalse(@preg_match($regex, ''), 'The row must be a pattern PHP compiles.');
        $parts = DelimitedPattern::fromDelimited($regex, PcreTarget::runtime());
        $this->assertSame($body, $parts->pattern);
        $this->assertSame($modifiers, $parts->flags);

        $payload = self::traceInput($driver, $root, $regex, $subject);

        $this->assertSame(3, $payload['protocol']);
        $this->assertNull($payload['error']);
        $this->assertNull($payload['refusal']);
        $this->assertNotNull($payload['input']);
        $this->assertSame($body, $payload['input']['body']);
        $this->assertSame($modifiers, $payload['input']['modifiers']);
        $this->assertSame($bodyStart, $payload['input']['bodyStart']);
        $this->assertSame($bodyStart + \strlen($body), $payload['input']['bodyEnd']);
        $this->assertSame($body, substr($regex, $payload['input']['bodyStart'], $payload['input']['bodyEnd'] - $payload['input']['bodyStart']), 'bodyStart and bodyEnd are byte offsets into the regex as given.');
        $this->assertSame($subject, $payload['input']['subject']);
        $this->assertSame(\strlen($subject), $payload['input']['subjectBytes']);
        $this->assertFalse($payload['input']['subjectTruncated']);
        $this->assertIsFloat($payload['timing']['traceInputMs']);
        $this->assertSame([], $payload['notices']);
    }

    /**
     * The e modifier is PHP's to refuse ("Unknown modifier 'e'" comes from
     * the engine's own run): the op reports it as PHP sees it, as a
     * modifier, not as an error of its own.
     */
    #[Test]
    #[DataProvider('provideDrivers')]
    public function test_trace_input_refuses_the_e_modifier_like_php_does(string $driver, string $root): void
    {
        // PHP refuses the pattern before matching; the library refuses it at
        // the split, with the same fault the analyze op reports. The page
        // shows the engine's own verdict, so no trace is offered.
        $regex = self::withModifier('e');
        $this->assertSame("preg_match(): Unknown modifier 'e'", EngineProbe::warning($regex));

        $payload = self::traceInput($driver, $root, $regex, 'a');

        $this->assertNotNull($payload['error']);
        $this->assertNull($payload['refusal']);
        $this->assertNull($payload['input']);
        $this->assertSame('regex.flag.removed_e', $payload['error']['code']);
        $analysis = PlaygroundDriver::analyze($driver, $root, $regex, '', null, [], false);
        $this->assertSame($analysis['errors'][0]['message'], $payload['error']['message'], 'The message is the one the analyze op reports for the same pattern.');
        $this->assertSame(3, $payload['protocol']);
        $this->assertIsFloat($payload['timing']['traceInputMs']);
    }

    /**
     * @return iterable<string, array{regex: string}>
     */
    public static function provideExplicitCallouts(): iterable
    {
        yield 'numbered callout' => ['regex' => '/(?C1)a/'];
        yield 'default callout number' => ['regex' => '/(?C)a/'];
        yield 'string callout' => ['regex' => '/a(?C"m")b/'];
    }

    /**
     * @return iterable<string, array{driver: string, root: string, regex: string}>
     */
    public static function provideExplicitCalloutsOnDrivers(): iterable
    {
        foreach (PlaygroundDriver::drivers() as $driverLabel => $driver) {
            foreach (self::provideExplicitCallouts() as $label => $row) {
                yield $label.' on the '.$driverLabel => $driver + $row;
            }
        }
    }

    /**
     * PHP ignores an explicit callout (the pattern matches); the trace
     * would interleave it with its automatic ones, so the op refuses it,
     * with the input still answered for the page to show.
     */
    #[Test]
    #[DataProvider('provideExplicitCalloutsOnDrivers')]
    public function test_trace_input_refuses_explicit_callouts(string $driver, string $root, string $regex): void
    {
        $this->assertSame(1, @preg_match($regex, 'ab'), 'PHP runs the pattern: the refusal is the trace\'s, not the engine\'s.');

        $payload = self::traceInput($driver, $root, $regex, 'ab');

        $this->assertNull($payload['error']);
        $this->assertIsString($payload['refusal']);
        $this->assertStringContainsString('callout', (string) $payload['refusal']);
        $this->assertNotNull($payload['input'], 'The input is still filled beside a refusal.');
        $this->assertSame(substr($regex, 1, -1), $payload['input']['body']);
        $this->assertSame('', $payload['input']['modifiers']);
        $this->assertSame(1, $payload['input']['bodyStart']);
        $this->assertSame(\strlen($regex) - 1, $payload['input']['bodyEnd']);
    }

    /**
     * @return iterable<string, array{regex: string, code: string}>
     */
    public static function provideInvalidPatterns(): iterable
    {
        yield 'alphanumeric delimiter' => ['regex' => 'abc', 'code' => 'regex.delimiter.invalid'];
        yield 'no closing delimiter' => ['regex' => '/abc', 'code' => 'regex.delimiter.unclosed'];
        yield 'empty pattern' => ['regex' => '', 'code' => 'regex.pattern.empty'];
        yield 'one byte past the pattern cap' => ['regex' => '/'.str_repeat('a', self::PATTERN_CAP - 1).'/', 'code' => 'regex.pattern.too_long'];
    }

    /**
     * @return iterable<string, array{driver: string, root: string, regex: string, code: string}>
     */
    public static function provideInvalidPatternsOnDrivers(): iterable
    {
        foreach (PlaygroundDriver::drivers() as $driverLabel => $driver) {
            foreach (self::provideInvalidPatterns() as $label => $row) {
                yield $label.' on the '.$driverLabel => $driver + $row;
            }
        }
    }

    /**
     * A pattern PHP's delimiter rules refuse, or one past the page's cap:
     * the error the analyze op reports for the same pattern, no input.
     */
    #[Test]
    #[DataProvider('provideInvalidPatternsOnDrivers')]
    public function test_trace_input_reports_invalid_delimiters_and_the_cap_as_errors(string $driver, string $root, string $regex, string $code): void
    {
        if (\strlen($regex) <= self::PATTERN_CAP) {
            $this->assertFalse(@preg_match($regex, ''), 'PHP refuses the pattern too.');
        }
        $analysis = PlaygroundDriver::analyze($driver, $root, $regex, '', null, [], false);
        $this->assertNotSame([], $analysis['errors']);

        $payload = self::traceInput($driver, $root, $regex, 'abc');

        $this->assertSame(3, $payload['protocol']);
        $this->assertNull($payload['input']);
        $this->assertNull($payload['refusal']);
        $this->assertNotNull($payload['error']);
        $this->assertSame($code, $payload['error']['code']);
        $this->assertSame($analysis['errors'][0]['message'], $payload['error']['message'], 'The message is the one the analyze op reports for the same pattern.');
        $this->assertIsFloat($payload['timing']['traceInputMs']);
    }

    /**
     * @return iterable<string, array{subject: string, expected: string, truncated: bool}>
     */
    public static function provideSubjects(): iterable
    {
        yield 'empty subject' => ['subject' => '', 'expected' => '', 'truncated' => false];
        yield 'at the cap' => ['subject' => str_repeat('a', self::SUBJECT_CAP), 'expected' => str_repeat('a', self::SUBJECT_CAP), 'truncated' => false];
        yield 'one byte past the cap' => ['subject' => str_repeat('a', self::SUBJECT_CAP + 1), 'expected' => str_repeat('a', self::SUBJECT_CAP), 'truncated' => true];
        yield 'a two-byte character straddling the cap is dropped whole' => ['subject' => str_repeat('a', self::SUBJECT_CAP - 1).'ézzz', 'expected' => str_repeat('a', self::SUBJECT_CAP - 1), 'truncated' => true];
        yield 'a two-byte character ending at the cap stays' => ['subject' => str_repeat('a', self::SUBJECT_CAP - 2).'ézzz', 'expected' => str_repeat('a', self::SUBJECT_CAP - 2).'é', 'truncated' => true];
        yield 'a four-byte character straddling the cap is dropped whole' => ['subject' => str_repeat('a', self::SUBJECT_CAP - 3).'😀z', 'expected' => str_repeat('a', self::SUBJECT_CAP - 3), 'truncated' => true];
    }

    /**
     * @return iterable<string, array{driver: string, root: string, subject: string, expected: string, truncated: bool}>
     */
    public static function provideSubjectsOnDrivers(): iterable
    {
        foreach (PlaygroundDriver::drivers() as $driverLabel => $driver) {
            foreach (self::provideSubjects() as $label => $row) {
                yield $label.' on the '.$driverLabel => $driver + $row;
            }
        }
    }

    #[Test]
    #[DataProvider('provideSubjectsOnDrivers')]
    public function test_subject_cap_cuts_on_a_character_boundary(string $driver, string $root, string $subject, string $expected, bool $truncated): void
    {
        $payload = self::traceInput($driver, $root, '/a/', $subject);

        $this->assertNotNull($payload['input']);
        $this->assertSame($expected, $payload['input']['subject']);
        $this->assertSame(\strlen($expected), $payload['input']['subjectBytes']);
        $this->assertSame($truncated, $payload['input']['subjectTruncated']);
        $this->assertLessThanOrEqual(self::SUBJECT_CAP, $payload['input']['subjectBytes']);
        $this->assertSame(1, preg_match('//u', $payload['input']['subject']), 'The cut subject is still valid UTF-8: the engine would refuse a split character under /u.');
    }

    /**
     * The pattern /a/ with a modifier PHP may refuse: spelled apart so the
     * refusal is the engine's at run time, not a static reading of a literal.
     */
    private static function withModifier(string $modifier): string
    {
        return '/a/'.$modifier;
    }

    /**
     * @return TraceInputAnswer
     */
    private static function traceInput(string $driver, string $root, string $regex, string $subject): array
    {
        $answer = PlaygroundDriver::tool($driver, $root, 'trace-input', $regex, ['subject' => $subject]);
        self::assertArrayNotHasKey('fatal', $answer, 'The driver refused the request: '.json_encode($answer['fatal'] ?? null));
        foreach (['protocol', 'notices', 'input', 'refusal', 'error', 'timing'] as $key) {
            self::assertArrayHasKey($key, $answer, 'The trace-input envelope.');
        }
        /** @var TraceInputAnswer $envelope */
        $envelope = $answer;

        return $envelope;
    }
}
