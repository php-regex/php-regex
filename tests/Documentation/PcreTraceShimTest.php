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

use PHPRegex\Tests\Support\NativePcre2;
use PHPRegex\Tests\Support\PlaygroundDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The C shim behind the step debugger, compiled natively against the host's
 * PCRE2 and run as a command: its JSON trace against pcre2test's own
 * auto-callout trace of the same release, pinned verbatim, and its outcomes
 * against preg_match() on the same engine. What is proved here is the shim
 * (the callout recording, the JSON, the error mapping); the wasm module
 * built from the same source is checked by bin/playground-check.
 *
 * The CLI contract: <bin> <compile_options> <extra_options> <match_limit>
 * <depth_limit> <step_cap> <pattern-file> <subject-file>, decimal integers,
 * the pattern and the subject as raw bytes in files, the trace JSON on
 * stdout, exit 0; <bin> --version prints the PCRE2 release.
 *
 * @phpstan-type Step array{p: int, len: int, s: int, n: int, flags: int, start: int, top: int, cap?: list<array{int, int}|null>}
 * @phpstan-type Trace array{pcre2: string, compile: array{error: array{code: int, offset: int, message: string}|null}, steps: list<Step>, steps_total: int, truncated: bool, outcome: array{rc: int, ovector: list<array{int, int}|null>, error: array{code: int, message: string}|null, mark: ?string}|null}
 * @phpstan-type ExpectedStep array{p: int, item: string, start: int, s: ?int, flags: int, caps: array<int, ?string>}
 * @phpstan-type Expected array{compileError: ?int, steps: list<ExpectedStep>, outcome: array{rc: int, group0: ?string}|null}
 * @phpstan-type Case array{body: string, subject: string, compile: int, extra: int, modifiers: string}
 */
#[Group('documentation')]
#[RequiresOperatingSystem('Linux|Darwin')]
final class PcreTraceShimTest extends TestCase
{
    use NativePcre2;

    private const SHIM = PlaygroundDriver::ROOT.'/wasm/pcre2-trace';
    private const FIXTURES = __DIR__.'/Fixtures/pcre2-trace';

    /**
     * The page's limits: the worker's ini and the recording cap.
     */
    private const MATCH_LIMIT = 1000000;
    private const DEPTH_LIMIT = 100000;
    private const STEP_CAP = 20000;

    /**
     * PCRE2's bits, stable from 10.3x to 10.49.
     */
    private const UTF = 0x80000;
    private const UCP = 0x20000;
    private const EXTENDED = 0x80;
    private const EXTRA_ALLOW_LOOKAROUND_BSK = 0x40;
    private const EXTRA_CASELESS_RESTRICT = 0x80;
    private const AUTO_CALLOUT_NUMBER = 255;
    private const STARTMATCH = 1;
    private const BACKTRACK = 2;

    /**
     * PHP's modifiers as compile bits (every row of the options table
     * agrees on these; n is 8.2+, r 8.4+ and an extra option).
     */
    private const MODIFIERS = ['i' => 0x8, 'm' => 0x400, 's' => 0x20, 'x' => 0x80, 'A' => 0x80000000, 'D' => 0x10, 'U' => 0x40000, 'u' => self::UTF | self::UCP, 'J' => 0x40, 'n' => 0x2000, 'S' => 0, 'X' => 0];
    private const EXTRA_MODIFIERS = ['r' => self::EXTRA_CASELESS_RESTRICT];

    /**
     * PHP's pcre_handle_exec_error: a PCRE2 match error to a PREG_* code.
     */
    private const PCRE2_ERROR_UTF8_ERR1 = -3;
    private const PCRE2_ERROR_UTF8_ERR21 = -23;
    private const PCRE2_ERROR_BADUTFOFFSET = -36;
    private const PCRE2_ERROR_MATCHLIMIT = -47;
    private const PCRE2_ERROR_DEPTHLIMIT = -53;
    private const PCRE2_ERROR_JIT_STACKLIMIT = -46;

    private static ?string $binary = null;

    private static ?string $buildDirectory = null;

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$buildDirectory) {
            foreach (glob(self::$buildDirectory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir(self::$buildDirectory);
            self::$buildDirectory = null;
            self::$binary = null;
        }
    }

    protected function setUp(): void
    {
        $this->requiresNativePcre2();
    }

    /**
     * @return iterable<string, array{name: string}>
     */
    public static function provideCases(): iterable
    {
        foreach (array_keys(self::cases()) as $name) {
            yield $name => ['name' => $name];
        }
    }

    #[Test]
    public function test_the_cases_file_names_every_case(): void
    {
        $named = preg_match_all('/^# name: (\S+)$/m', (string) file_get_contents(self::FIXTURES.'/cases.txt'), $m);

        $this->assertGreaterThan(0, $named);
        $this->assertSame(array_keys(self::cases()), $m[1], 'cases.txt and the table above must list the same cases in the same order.');
    }

    #[Test]
    public function test_version_prints_the_release(): void
    {
        $release = self::release();

        $this->assertMatchesRegularExpression('/^10\.\d{2}$/', $release);
        $this->assertSame(trim((string) shell_exec('pcre2-config --version')), $release, 'The shim links the pcre2 that pcre2-config describes.');
    }

    /**
     * pcre2test's auto-callout trace of each case, pinned for the release
     * the shim links, read back into steps and compared with the shim's
     * JSON: pattern position and item length, cursor, flags, captures.
     */
    #[Test]
    #[DataProvider('provideCases')]
    public function test_shim_trace_matches_pcre2test(string $name): void
    {
        $release = self::release();
        $this->requiresPinnedTraces($release);
        $case = self::cases()[$name];
        $blocks = self::pinnedBlocks($release);
        $this->assertArrayHasKey($name, $blocks, \sprintf('%s.txt carries no "# name: %s" block: re-pin it from cases.txt.', $release, $name));
        $utf = 0 !== ($case['compile'] & self::UTF);
        $expected = self::expected($blocks[$name], $case['subject'], $utf);

        $trace = self::trace($case['body'], $case['subject'], $case['compile'], $case['extra']);

        $this->assertSame($release, $trace['pcre2']);
        if (null !== $expected['compileError']) {
            $this->assertNotNull($trace['compile']['error'], $name.': pcre2test refuses the pattern, the shim must too.');
            $this->assertSame($expected['compileError'], $trace['compile']['error']['code'], $name);
            $this->assertSame([], $trace['steps'], $name);
            $this->assertNull($trace['outcome'], $name);

            return;
        }

        $this->assertNull($trace['compile']['error'], $name.': '.json_encode($trace['compile']));
        $this->assertFalse($trace['truncated'], $name);
        $this->assertCount(\count($expected['steps']), $trace['steps'], $name.': the number of callouts.');
        $this->assertSame(\count($expected['steps']), $trace['steps_total'], $name);

        $cap = null;
        foreach ($expected['steps'] as $i => $want) {
            $step = $trace['steps'][$i];
            $label = \sprintf('%s, step %d (%s at +%d)', $name, $i, $want['item'], $want['p']);
            $this->assertSame(self::AUTO_CALLOUT_NUMBER, $step['n'], $label);
            $this->assertSame($want['p'], $step['p'], $label.': pattern_position');
            if ('End of pattern' === $want['item']) {
                $this->assertSame(\strlen($case['body']), $step['p'], $label);
                $this->assertSame(0, $step['len'], $label.': next_item_length at the end');
            } else {
                $this->assertSame(\strlen($want['item']), $step['len'], $label.': next_item_length');
                $this->assertSame($want['item'], substr($case['body'], $step['p'], $step['len']), $label.': the item is the body at p');
            }
            $this->assertSame($want['flags'], $step['flags'], $label.': callout_flags');
            $this->assertSame($want['start'], $step['start'], $label.': start_match');
            if (null !== $want['s']) {
                $this->assertSame($want['s'], $step['s'], $label.': current_position');
            } else {
                // pcre2test prints one caret when the cursor is at the start of
                // the match or before it (inside a lookbehind).
                $this->assertLessThanOrEqual($want['start'], $step['s'], $label.': current_position at or before start_match');
            }

            if (\array_key_exists('cap', $step)) {
                if (0 !== $i) {
                    $this->assertNotSame($cap, $step['cap'], $label.': cap is sent only when it changed.');
                }
                $cap = $step['cap'];
            } else {
                $this->assertNotNull($cap, $label.': the first step always carries cap.');
            }
            $this->assertSame(1 + \count($want['caps']), $step['top'], $label.': capture_top');
            $this->assertCount($step['top'], $cap, $label.': cap holds the pairs below capture_top');
            foreach ($want['caps'] as $group => $text) {
                $pair = $cap[$group];
                if (null === $text) {
                    $this->assertNull($pair, $label.': group '.$group.' is unset');
                } else {
                    $this->assertIsArray($pair, $label.': group '.$group.' is set');
                    $this->assertSame($text, substr($case['subject'], $pair[0], $pair[1] - $pair[0]), $label.': group '.$group);
                }
            }
        }

        $outcome = $trace['outcome'];
        $this->assertNotNull($outcome, $name);
        $this->assertNotNull($expected['outcome'], $name.': the pinned trace ends with a match or "No match".');
        $this->assertNull($outcome['error'], $name);
        if (-1 === $expected['outcome']['rc']) {
            $this->assertSame(-1, $outcome['rc'], $name.': no match');
        } else {
            $this->assertGreaterThanOrEqual(1, $outcome['rc'], $name.': a match');
            $this->assertIsArray($outcome['ovector'][0]);
            $this->assertSame($expected['outcome']['group0'], substr($case['subject'], $outcome['ovector'][0][0], $outcome['ovector'][0][1] - $outcome['ovector'][0][0]), $name.': the match');
        }
    }

    /**
     * Every case above and the pinned conformance rows: rc, the captures
     * after PHP's rule (unset is "", nothing past the highest set group)
     * and the PREG_* error code agree between the shim run with PHP's
     * options and preg_match() with the JIT off, on one release.
     */
    #[Test]
    public function test_shim_outcomes_match_preg_match(): void
    {
        $this->requiresSameRelease(self::release(), explode(' ', \PCRE_VERSION)[0]);

        $rows = [];
        foreach (self::cases() as $name => $case) {
            $rows[] = ['id' => $name, 'regex' => '/'.$case['body'].'/'.$case['modifiers'], 'subject' => $case['subject']];
        }
        /** @var list<array{id: string, regex: string, subject: string}> $pinned */
        $pinned = json_decode((string) file_get_contents(self::FIXTURES.'/outcomes.json'), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertGreaterThanOrEqual(300, \count($pinned));
        $rows = array_merge($rows, $pinned);

        $php = self::pregMatchAll($rows);
        $this->assertCount(\count($rows), $php);

        foreach ($rows as $i => $row) {
            $label = $row['id'].' '.$row['regex'].' on '.json_encode($row['subject']);
            $this->assertSame(1, preg_match('~^/(.*)/([A-Za-z]*)$~s', $row['regex'], $m), $label);
            [$compile, $extra] = self::phpOptions($m[2]);
            $trace = self::trace($m[1], $row['subject'], $compile, $extra);
            $answer = $php[$i];

            if (null !== $trace['compile']['error']) {
                $this->assertFalse($answer['rc'], $label.': the shim refuses to compile ('.$trace['compile']['error']['message'].'), PHP must too.');
                $this->assertSame(\PREG_INTERNAL_ERROR, $answer['error'], $label);

                continue;
            }
            $outcome = $trace['outcome'];
            $this->assertNotNull($outcome, $label);
            $whole = $outcome['ovector'][0] ?? null;
            if ($outcome['rc'] >= 1 && null !== $whole && $whole[1] < $whole[0]) {
                // \K in a lookahead moves the start past the end: PCRE2 reports
                // the match, PHP refuses it at match time ("Get subpatterns
                // list failed", a warning, no PREG error) when a matches
                // array is passed — the oracle every panel of the page uses.
                $this->assertFalse($answer['rc'], $label.': PHP refuses a match that ends before it starts');
                $this->assertSame(\PREG_NO_ERROR, $answer['error'], $label);

                continue;
            }
            if ($outcome['rc'] >= 1) {
                $this->assertSame(1, $answer['rc'], $label.': the shim matched');
                $this->assertSame(\PREG_NO_ERROR, $answer['error'], $label);
                $this->assertCount($outcome['rc'], $answer['matches'], $label.': PHP stops at the highest set group');
                foreach ($answer['matches'] as $group => $text) {
                    $pair = $outcome['ovector'][$group] ?? null;
                    $this->assertSame($text, null === $pair ? '' : substr($row['subject'], $pair[0], $pair[1] - $pair[0]), $label.': group '.$group);
                }
            } elseif (-1 === $outcome['rc']) {
                $this->assertSame(0, $answer['rc'], $label.': the shim did not match');
                $this->assertSame(\PREG_NO_ERROR, $answer['error'], $label);
            } else {
                $this->assertNotNull($outcome['error'], $label);
                $this->assertSame($outcome['rc'], $outcome['error']['code'], $label);
                $this->assertFalse($answer['rc'], $label.': the shim failed with '.$outcome['error']['message']);
                $this->assertSame(self::pregCode($outcome['rc']), $answer['error'], $label.': the PREG_* code');
            }
        }
    }

    #[Test]
    public function test_the_record_size_cap_stops_recording_and_keeps_the_outcome(): void
    {
        // 250 groups whose ovector changes on every step: the steps alone
        // would weigh tens of megabytes before the step cap. The record
        // stops at its byte cap instead; the match goes on to PHP's answer.
        $body = str_repeat('(a)', 249).'(?:(a))+(?:z|y)';
        $subject = str_repeat('a', 300).'q';
        $this->assertSame(0, @preg_match('/'.$body.'/', $subject));

        $trace = self::trace($body, $subject, 0, 0, 20000);

        $this->assertNull($trace['compile']['error']);
        $this->assertTrue($trace['truncated']);
        $this->assertGreaterThan(0, \count($trace['steps']));
        $this->assertLessThan(20000, \count($trace['steps']), 'The byte cap stops the record before the step cap.');
        $this->assertGreaterThan(\count($trace['steps']), $trace['steps_total']);
        $this->assertLessThan(10 * 1024 * 1024, \strlen((string) json_encode($trace['steps'])), 'The recorded steps stay under the byte cap.');
        $this->assertNotNull($trace['outcome']);
        $this->assertSame(-1, $trace['outcome']['rc'], 'The outcome is the engine\'s.');
        $this->assertNull($trace['outcome']['error']);
    }

    #[Test]
    public function test_step_cap_stops_recording_and_keeps_the_outcome(): void
    {
        $subject = str_repeat('a', 15).'b';
        $this->assertSame(0, @preg_match('/^(a+)+$/', $subject));

        $trace = self::trace('^(a+)+$', $subject, 0, 0, 100);

        $this->assertNull($trace['compile']['error']);
        $this->assertTrue($trace['truncated']);
        $this->assertCount(100, $trace['steps']);
        $this->assertGreaterThan(100, $trace['steps_total']);
        $this->assertNotNull($trace['outcome']);
        $this->assertSame(-1, $trace['outcome']['rc'], 'The outcome is the engine\'s: recording stopped, the match went on.');
        $this->assertNull($trace['outcome']['error']);
    }

    /**
     * @return iterable<string, array{body: string, subject: string}>
     */
    public static function provideInvalidUtf(): iterable
    {
        yield 'an invalid byte in the subject' => ['body' => '\w+', 'subject' => "a\xffb"];
        yield 'an invalid byte in the pattern' => ['body' => "\xff", 'subject' => 'a'];
    }

    #[Test]
    #[DataProvider('provideInvalidUtf')]
    public function test_utf_error_maps_like_php(string $body, string $subject): void
    {
        $this->assertFalse(@preg_match('/'.$body.'/u', $subject));
        $pregError = preg_last_error();

        $trace = self::trace($body, $subject, self::UTF | self::UCP, 0);

        if (null !== $trace['compile']['error']) {
            $this->assertSame(\PREG_INTERNAL_ERROR, $pregError, 'PCRE2 refused the pattern: PHP reports a compile failure as an internal error.');
            $this->assertNull($trace['outcome']);

            return;
        }
        $this->assertSame(\PREG_BAD_UTF8_ERROR, $pregError);
        $this->assertNotNull($trace['outcome']);
        $this->assertNotNull($trace['outcome']['error']);
        $code = $trace['outcome']['error']['code'];
        $this->assertSame($code, $trace['outcome']['rc']);
        $this->assertGreaterThanOrEqual(self::PCRE2_ERROR_UTF8_ERR21, $code);
        $this->assertLessThanOrEqual(self::PCRE2_ERROR_UTF8_ERR1, $code);
        $this->assertSame(\PREG_BAD_UTF8_ERROR, self::pregCode($code));
        $this->assertSame([], $trace['steps'], 'The UTF check fails before any callout.');
    }

    #[Test]
    public function test_explicit_callout_numbers_are_reported(): void
    {
        $this->assertSame(1, @preg_match('/(?C7)a/', 'a'));

        $trace = self::trace('(?C7)a', 'a', 0, 0);

        $this->assertNull($trace['compile']['error']);
        $numbers = array_column($trace['steps'], 'n');
        $this->assertContains(7, $numbers);
        $this->assertSame([7], array_values(array_unique(array_filter($numbers, static fn (int $n): bool => self::AUTO_CALLOUT_NUMBER !== $n))));
        $this->assertContains(self::AUTO_CALLOUT_NUMBER, $numbers);
        $this->assertNotNull($trace['outcome']);
        $this->assertSame(1, $trace['outcome']['rc']);
    }

    /**
     * The cases of Fixtures/pcre2-trace/cases.txt, by name: the corpus and
     * the edge cases. The pcre2test modifiers of each
     * pattern as the bits the shim takes, and the PHP modifiers the same
     * pattern carries for the outcome comparison (u is UTF|UCP in PHP).
     *
     * @return array<string, Case>
     */
    private static function cases(): array
    {
        return [
            'nested-quantifier' => ['body' => '^(a+)+$', 'subject' => 'aab', 'compile' => 0, 'extra' => 0, 'modifiers' => ''],
            'leftmost-first' => ['body' => '(a|ab)(c|bcd)(d*)', 'subject' => 'abcd', 'compile' => 0, 'extra' => 0, 'modifiers' => ''],
            'possessive' => ['body' => 'a++a', 'subject' => 'aaa', 'compile' => 0, 'extra' => 0, 'modifiers' => ''],
            'atomic' => ['body' => '(?>a+)a', 'subject' => 'aaa', 'compile' => 0, 'extra' => 0, 'modifiers' => ''],
            'lookbehind' => ['body' => '(?<=b)a+', 'subject' => 'xba', 'compile' => 0, 'extra' => 0, 'modifiers' => ''],
            'lookaround-bsk' => ['body' => '(?<=a\K)bc', 'subject' => 'abc', 'compile' => 0, 'extra' => self::EXTRA_ALLOW_LOOKAROUND_BSK, 'modifiers' => ''],
            'ucp-word' => ['body' => '\w+', 'subject' => "caf\u{e9}", 'compile' => self::UTF | self::UCP, 'extra' => 0, 'modifiers' => 'u'],
            'empty-body' => ['body' => '', 'subject' => '', 'compile' => 0, 'extra' => 0, 'modifiers' => ''],
            'empty-subject' => ['body' => 'a?', 'subject' => '', 'compile' => 0, 'extra' => 0, 'modifiers' => ''],
            'extended-comment' => ['body' => "a # comment\n b", 'subject' => 'ab', 'compile' => self::EXTENDED, 'extra' => 0, 'modifiers' => 'x'],
            'dupnames' => ['body' => '(?J)(?<n>a)|(?<n>b)', 'subject' => 'b', 'compile' => 0, 'extra' => 0, 'modifiers' => ''],
            'no-auto-capture' => ['body' => '(?n)(a)(?<x>b)', 'subject' => 'ab', 'compile' => 0, 'extra' => 0, 'modifiers' => ''],
            'lookbehind-at-start' => ['body' => '(?<=^)a', 'subject' => 'a', 'compile' => 0, 'extra' => 0, 'modifiers' => ''],
            'crlf-dollar' => ['body' => 'a$', 'subject' => "a\r\n", 'compile' => 0, 'extra' => 0, 'modifiers' => ''],
            'bsr-nel-utf' => ['body' => '\R', 'subject' => "\u{85}", 'compile' => self::UTF, 'extra' => 0, 'modifiers' => 'u'],
            'auto-possess' => ['body' => 'a+[bc]', 'subject' => 'aaac', 'compile' => 0, 'extra' => 0, 'modifiers' => ''],
            'recursion' => ['body' => '^(\((?1)*\))$', 'subject' => '(()())', 'compile' => 0, 'extra' => 0, 'modifiers' => ''],
            'backreference' => ['body' => '(a)\1', 'subject' => 'aa', 'compile' => 0, 'extra' => 0, 'modifiers' => ''],
            'branch-reset' => ['body' => '(?|(a)|(b))\1', 'subject' => 'bb', 'compile' => 0, 'extra' => 0, 'modifiers' => ''],
            'conditional' => ['body' => '(a)?(?(1)b|c)', 'subject' => 'c', 'compile' => 0, 'extra' => 0, 'modifiers' => ''],
            'skip-verb' => ['body' => 'a+(*SKIP)b|c', 'subject' => 'aaac', 'compile' => 0, 'extra' => 0, 'modifiers' => ''],
            'nest-limit' => ['body' => str_repeat('(', 251).str_repeat(')', 251), 'subject' => '', 'compile' => 0, 'extra' => 0, 'modifiers' => ''],
        ];
    }

    // --- the shim as a command ----------------------------------------------

    private static function release(): string
    {
        $run = self::command([self::binary(), '--version']);
        self::assertSame(0, $run['exit'], $run['stderr']);

        return trim($run['stdout']);
    }

    /**
     * @return Trace
     */
    private static function trace(string $body, string $subject, int $compile, int $extra, int $stepCap = self::STEP_CAP): array
    {
        $dir = self::buildDirectory();
        $pattern = $dir.'/pattern-'.bin2hex(random_bytes(4));
        $subjectFile = $dir.'/subject-'.bin2hex(random_bytes(4));
        file_put_contents($pattern, $body);
        file_put_contents($subjectFile, $subject);

        try {
            $run = self::command([self::binary(), (string) $compile, (string) $extra, (string) self::MATCH_LIMIT, (string) self::DEPTH_LIMIT, (string) $stepCap, $pattern, $subjectFile]);
        } finally {
            unlink($pattern);
            unlink($subjectFile);
        }
        self::assertSame(0, $run['exit'], 'The shim failed: '.$run['stderr']);
        self::assertSame('', $run['stderr']);
        /** @var Trace|null $trace */
        $trace = json_decode($run['stdout'], true, 64, \JSON_THROW_ON_ERROR);
        self::assertIsArray($trace, 'The shim prints one JSON document.');

        return $trace;
    }

    /**
     * The shim compiled once per process from the tree's sources against
     * the host's pcre2.
     */
    private static function binary(): string
    {
        if (null !== self::$binary) {
            return self::$binary;
        }
        foreach (['trace.h', 'trace.c', 'trace-cli.c'] as $file) {
            self::assertFileExists(self::SHIM.'/'.$file);
        }
        $binary = self::buildDirectory().'/pcre2-trace';
        $compile = \sprintf(
            'cc -O1 -o %s %s %s %s %s 2>&1',
            escapeshellarg($binary),
            escapeshellarg(self::SHIM.'/trace.c'),
            escapeshellarg(self::SHIM.'/trace-cli.c'),
            trim((string) shell_exec('pcre2-config --cflags')),
            self::library(),
        );
        exec($compile, $output, $exit);
        self::assertSame(0, $exit, "The shim does not compile:\n".$compile."\n".implode("\n", $output));
        self::assertFileExists($binary);

        return self::$binary = $binary;
    }

    /**
     * The library the headers describe, linked statically when its archive
     * is there: a host may carry another libpcre2-8 ahead of it for the
     * dynamic linker (a PHP build of its own, a newer release), and the shim
     * must run the release it was compiled for.
     */
    private static function library(): string
    {
        $libs = trim((string) shell_exec('pcre2-config --libs8'));
        preg_match_all('/(?:^|\s)-L(\S+)/', $libs, $dirs);
        $candidates = $dirs[1];
        $pkgconfig = trim((string) shell_exec('pkg-config --variable=libdir libpcre2-8 2>/dev/null'));
        if ('' !== $pkgconfig) {
            $candidates[] = $pkgconfig;
        }
        $candidates[] = trim((string) shell_exec('pcre2-config --prefix')).'/lib';
        foreach ($candidates as $dir) {
            if (is_file($dir.'/libpcre2-8.a')) {
                return escapeshellarg($dir.'/libpcre2-8.a');
            }
        }

        return $libs;
    }

    private static function buildDirectory(): string
    {
        if (null === self::$buildDirectory) {
            $dir = sys_get_temp_dir().'/phpregex-pcre2-trace-'.bin2hex(random_bytes(6));
            mkdir($dir);
            self::$buildDirectory = $dir;
        }

        return self::$buildDirectory;
    }

    /**
     * @param list<string> $command
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private static function command(array $command, ?string $stdin = null): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        if (null !== $stdin) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    // --- PHP's side ----------------------------------------------------------

    /**
     * PHP's compile and extra options for a modifier string, as the host's
     * wrapper row has them: BSK on every pattern up to PHP 8.4, gone in 8.5.
     *
     * @return array{int, int}
     */
    private static function phpOptions(string $modifiers): array
    {
        $compile = 0;
        $extra = \PHP_VERSION_ID < 80500 ? self::EXTRA_ALLOW_LOOKAROUND_BSK : 0;
        foreach (str_split($modifiers) as $modifier) {
            if ('' === $modifier) {
                continue;
            }
            if (isset(self::EXTRA_MODIFIERS[$modifier])) {
                $extra |= self::EXTRA_MODIFIERS[$modifier];

                continue;
            }
            self::assertArrayHasKey($modifier, self::MODIFIERS, 'A modifier PHP reads.');
            $compile |= self::MODIFIERS[$modifier];
        }

        return [$compile, $extra];
    }

    /**
     * pcre_handle_exec_error, as PHP 8.1 to 8.5 write it.
     */
    private static function pregCode(int $pcre2): int
    {
        return match (true) {
            self::PCRE2_ERROR_MATCHLIMIT === $pcre2 => \PREG_BACKTRACK_LIMIT_ERROR,
            self::PCRE2_ERROR_DEPTHLIMIT === $pcre2 => \PREG_RECURSION_LIMIT_ERROR,
            self::PCRE2_ERROR_BADUTFOFFSET === $pcre2 => \PREG_BAD_UTF8_OFFSET_ERROR,
            self::PCRE2_ERROR_JIT_STACKLIMIT === $pcre2 => \PREG_JIT_STACKLIMIT_ERROR,
            $pcre2 <= self::PCRE2_ERROR_UTF8_ERR1 && $pcre2 >= self::PCRE2_ERROR_UTF8_ERR21 => \PREG_BAD_UTF8_ERROR,
            default => \PREG_INTERNAL_ERROR,
        };
    }

    /**
     * preg_match() over every row in one child PHP with the JIT off (the
     * match limit is counted differently under the JIT): rc, the numbered
     * captures, the PREG_* code.
     *
     * @param list<array{id: string, regex: string, subject: string}> $rows
     *
     * @return list<array{rc: int|false, matches: list<string>, error: int}>
     */
    private static function pregMatchAll(array $rows): array
    {
        $script = <<<'PHP'
            <?php
            $rows = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
            $answers = [];
            foreach ($rows as $row) {
                $rc = @preg_match($row['regex'], $row['subject'], $m);
                $matches = [];
                foreach ($m ?? [] as $key => $text) {
                    if (is_int($key)) {
                        $matches[$key] = $text;
                    }
                }
                $answers[] = ['rc' => $rc, 'matches' => array_values($matches), 'error' => preg_last_error()];
            }
            echo json_encode($answers, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            PHP;
        $file = self::buildDirectory().'/preg-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($file, $script);

        try {
            // The local php.ini stays (a PHP loading mbstring from conf.d
            // would have none under -n); only a prepend file is set aside.
            $run = self::command([\PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcre.jit=0', $file], json_encode($rows, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE));
        } finally {
            unlink($file);
        }
        self::assertSame(0, $run['exit'], $run['stderr']);
        /** @var list<array{rc: int|false, matches: list<string>, error: int}> $answers */
        $answers = json_decode($run['stdout'], true, 512, \JSON_THROW_ON_ERROR);

        return $answers;
    }

    // --- the pinned pcre2test text ------------------------------------------

    /**
     * The pinned output split by "# name:" line, the lines of each case.
     *
     * @return array<string, list<string>>
     */
    private static function pinnedBlocks(string $release): array
    {
        $blocks = [];
        $name = null;
        foreach (explode("\n", (string) file_get_contents(self::FIXTURES.'/'.$release.'.txt')) as $line) {
            if (1 === preg_match('/^# name: (\S+)$/', $line, $m)) {
                $name = $m[1];
                $blocks[$name] = [];

                continue;
            }
            if (null !== $name) {
                $blocks[$name][] = $line;
            }
        }

        return $blocks;
    }

    /**
     * One case's pcre2test text as steps: "New match attempt" is the
     * STARTMATCH flag and "Backtrack" the BACKTRACK flag of the next item;
     * " +p ^ ^ item" gives the pattern position, the start of the match and
     * the cursor from the caret columns over the subject display, and the
     * item text; the capture lines after "Callout 255:" the groups below
     * capture_top. A "Failed: error N" line is a compile error.
     *
     * @param list<string> $lines
     *
     * @return Expected
     */
    private static function expected(array $lines, string $subject, bool $utf): array
    {
        $display = self::display($subject, $utf);
        $columns = self::columns($subject, $utf);
        $width = \strlen($display);

        $compileError = null;
        $steps = [];
        $outcome = null;
        $flags = 0;
        $caps = [];
        $afterSubject = false;
        $inOutcome = false;
        foreach ($lines as $line) {
            if (!$afterSubject) {
                if (1 === preg_match('/^Failed: error (\d+)/', $line, $m)) {
                    $compileError = (int) $m[1];
                }
                $afterSubject = str_ends_with($line, '\=callout_extra,callout_capture');

                continue;
            }
            if ('' === $line || 'No other matching paths' === $line) {
                continue;
            }
            if ('New match attempt' === $line) {
                $flags |= self::STARTMATCH;

                continue;
            }
            if ('Backtrack' === $line) {
                $flags |= self::BACKTRACK;

                continue;
            }
            if ('No match' === $line) {
                $outcome = ['rc' => -1, 'group0' => null];

                continue;
            }
            if (1 === preg_match('/^Callout (\d+): last capture = \d+$/', $line, $m)) {
                self::assertSame(self::AUTO_CALLOUT_NUMBER, (int) $m[1], 'The pinned trace holds automatic callouts only.');
                $caps = [];

                continue;
            }
            if (1 === preg_match('/^ (\d+): (.*)$/', $line, $m)) {
                $group = (int) $m[1];
                if (0 === $group) {
                    $inOutcome = true;
                    $outcome = ['rc' => 1, 'group0' => self::decode($m[2], $utf)];
                } elseif (!$inOutcome) {
                    $caps[$group] = '<unset>' === $m[2] ? null : self::decode($m[2], $utf);
                }

                continue;
            }
            if (str_starts_with($line, '--->')) {
                self::assertSame('--->'.$display, $line, 'The subject as pcre2test displays it.');

                continue;
            }
            if ([] !== $steps && 1 !== preg_match('/^\s*\+\d+ /', $line)) {
                // pcre2test 10.42 and 10.44 print a newline inside an item
                // raw (10.49 spells it \x0a): this line continues the item.
                $last = array_pop($steps);
                $last['item'] .= "\n".self::decode($line, $utf);
                $steps[] = $last;

                continue;
            }
            self::assertSame(1, preg_match('/^\s*\+(\d+) $/', substr($line, 0, 4), $m), 'A callout line: '.$line);
            $region = substr($line, 4, $width + 5);
            $item = substr($line, 4 + $width + 5);
            $first = strpos($region, '^');
            self::assertIsInt($first, 'A caret under the subject: '.$line);
            $second = strpos($region, '^', $first + 1);
            self::assertArrayHasKey($first, $columns, 'The start caret sits on a character boundary: '.$line);
            $steps[] = [
                'p' => (int) $m[1],
                'item' => self::decode($item, $utf),
                'start' => $columns[$first],
                's' => false === $second ? null : $columns[$second],
                'flags' => $flags,
                'caps' => $caps,
            ];
            $flags = 0;
        }

        return ['compileError' => $compileError, 'steps' => $steps, 'outcome' => $outcome];
    }

    /**
     * The subject as pcre2test prints it after "--->": printable ASCII as
     * is, anything else as \xhh (bytes) or \x{hh} (code points under utf).
     */
    private static function display(string $subject, bool $utf): string
    {
        $out = '';
        foreach (self::characters($subject, $utf) as [$code]) {
            $out .= self::pchar($code, $utf);
        }

        return $out;
    }

    /**
     * The display column of every character boundary of the subject, to
     * its byte offset.
     *
     * @return array<int, int>
     */
    private static function columns(string $subject, bool $utf): array
    {
        $columns = [0 => 0];
        $column = 0;
        $offset = 0;
        foreach (self::characters($subject, $utf) as [$code, $bytes]) {
            $column += \strlen(self::pchar($code, $utf));
            $offset += $bytes;
            $columns[$column] = $offset;
        }

        return $columns;
    }

    /**
     * @return list<array{int, int}> code and byte length per character
     */
    private static function characters(string $subject, bool $utf): array
    {
        $characters = [];
        if ($utf) {
            foreach (preg_split('//u', $subject, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
                $characters[] = [(int) mb_ord($character, 'UTF-8'), \strlen($character)];
            }
        } else {
            foreach (str_split($subject) as $byte) {
                if ('' !== $byte) {
                    $characters[] = [\ord($byte), 1];
                }
            }
        }

        return $characters;
    }

    private static function pchar(int $code, bool $utf): string
    {
        if ($code >= 32 && $code < 127) {
            return \chr($code);
        }

        return $utf ? \sprintf('\x{%02x}', $code) : \sprintf('\x%02x', $code);
    }

    /**
     * pcre2test's display of pattern or subject text back to bytes.
     */
    private static function decode(string $display, bool $utf): string
    {
        return (string) preg_replace_callback('/\\\\x\{([0-9a-f]+)\}|\\\\x([0-9a-f]{2})/', static function (array $m) use ($utf): string {
            if (isset($m[2])) {
                return \chr((int) hexdec($m[2]));
            }
            $code = (int) hexdec($m[1]);

            return $utf ? (string) mb_chr($code, 'UTF-8') : \chr($code);
        }, $display);
    }
}
