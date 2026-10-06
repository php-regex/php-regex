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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan;

use PHPRegex\PHPStan\RegexPatternRule;
use PHPStan\Analyser\Error;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The pattern a ReDoS message quotes is the one the console shows: valid
 * UTF-8 however it is cut, on one line, without the characters that move
 * or hide text in a terminal, and it reads back with the matches of the
 * pattern it was written from.
 *
 * Every pattern of the fixture fails preg_match() with "Backtrack limit
 * exhausted" on "a" x 30 . "!" (PHP 8.4, PCRE2 10.49, JIT off).
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexParserRuleRedosMessageRenderingTest extends RuleTestCase
{
    private const FIXTURE = __DIR__.'/Fixtures/RedosMessageRenderingFixture.php';

    /**
     * "/(?:x" is five bytes: the first "é" takes bytes 5 and 6, so byte 50
     * is the second byte of an "é" and a cut there leaves half of it.
     */
    #[Test]
    public function test_redos_message_cuts_a_long_pattern_on_a_character_boundary(): void
    {
        $pattern = '/(?:x'.str_repeat('é', 60).')?(a+)+$/u';
        $this->assertFalse(mb_check_encoding(substr($pattern, 0, 50), 'UTF-8'), 'Byte 50 falls inside a character.');

        $message = $this->errorOnLine(20)->getMessage();
        $shown = self::quotedPattern($message);

        $this->assertTrue(mb_check_encoding($message, 'UTF-8'), $message);
        $this->assertStringEndsWith('...', $shown);
        $head = substr($shown, 0, -3);
        $this->assertGreaterThanOrEqual(49, \strlen($head), 'The cut drops no more than the character it falls in.');
        if ('' === $head) {
            $this->fail('The message quotes nothing of the pattern.');
        }
        $this->assertStringStartsWith($head, $pattern, $head);
    }

    /**
     * The pattern quoted in the message is one line and reads back with the
     * matches of the pattern itself.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideDisplayedPatterns')]
    public function test_redos_message_quotes_the_pattern_as_the_console_shows_it(int $line, string $pattern, string $raw, array $subjects): void
    {
        $message = $this->errorOnLine($line)->getMessage();
        $shown = self::quotedPattern($message);

        $this->assertStringNotContainsString("\n", $message);
        $this->assertStringNotContainsString("\r", $message);
        $this->assertStringNotContainsString($raw, $message);
        $this->assertTrue(mb_check_encoding($message, 'UTF-8'), $message);

        foreach ($subjects as $subject) {
            $this->assertSame(
                self::oracle($pattern, $subject),
                self::oracle($shown, $subject),
                \sprintf('%s shown as %s on %s', json_encode($pattern), json_encode($shown), json_encode($subject)),
            );
        }
    }

    /**
     * @return iterable<string, array{line: int, pattern: string, raw: string, subjects: list<string>}>
     */
    public static function provideDisplayedPatterns(): iterable
    {
        // Oracle: "aaa" and "aa\n" match, "a a", "b" and the comment text do not.
        yield 'a comment and a line break under x' => [
            'line' => 21,
            'pattern' => "/(a+)+ # one or more runs\n \$/x",
            'raw' => 'one or more runs',
            'subjects' => ['aaa', "aa\n", 'a a', 'b', 'aa # one or more runs'],
        ];
        yield 'a right-to-left override under u' => [
            'line' => 22,
            'pattern' => "/(?:\u{202E})?(a+)+\$/u",
            'raw' => "\u{202E}",
            'subjects' => ["\u{202E}aa", 'aa', "\u{202D}aa", 'b'],
        ];
        yield 'a next-line control under u' => [
            'line' => 23,
            'pattern' => "/(?:\u{85})?(a+)+\$/u",
            'raw' => "\u{85}",
            'subjects' => ["\u{85}aa", 'aa', "\u{86}aa", 'b'],
        ];
        yield 'a right-to-left override in byte mode' => [
            'line' => 24,
            'pattern' => "/(?:\u{202E})?(a+)+\$/",
            'raw' => "\u{202E}",
            'subjects' => ["\u{202E}aa", 'aa', "\xE2aa", "\u{202D}aa", 'b'],
        ];
    }

    /**
     * The spelling follows the pattern's mode: a code point under u, its
     * bytes otherwise ("\x{202E}" is no byte without u).
     */
    #[Test]
    public function test_redos_message_spells_a_hidden_character_in_the_pattern_mode(): void
    {
        $this->assertMatchesRegularExpression('/\\\\x\{0*202E\}/i', $this->errorOnLine(22)->getMessage());
        $this->assertMatchesRegularExpression('/\\\\x\{0*85\}/i', $this->errorOnLine(23)->getMessage());
        $this->assertMatchesRegularExpression('/\\\\xE2\\\\x80\\\\xAE/i', $this->errorOnLine(24)->getMessage());
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: [
            'checks' => [
                'lint' => ['enabled' => false],
                'redos' => ['enabled' => true, 'threshold' => 'low'],
                'optimizations' => ['enabled' => false],
            ],
        ]);
    }

    private function errorOnLine(int $line): Error
    {
        foreach ($this->gatherAnalyserErrors([self::FIXTURE]) as $error) {
            if ($line === $error->getLine() && RegexPatternRule::IDENTIFIER_REDOS === $error->getIdentifier()) {
                return $error;
            }
        }

        $this->fail('No ReDoS error on line '.$line.': '.json_encode(array_map(
            static fn (Error $error): array => [$error->getLine(), $error->getIdentifier(), $error->getMessage()],
            $this->gatherAnalyserErrors([self::FIXTURE]),
        ), \JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private static function quotedPattern(string $message): string
    {
        self::assertSame(1, preg_match('/^\w+ backtracking \(ReDoS\): (.*)$/s', $message, $match), $message);

        return $match[1];
    }

    /**
     * What preg_match() answers, JIT off; a compilation failure or an engine
     * error is its message.
     */
    private static function oracle(string $pattern, string $subject): int|string
    {
        $jit = (string) \ini_get('pcre.jit');
        \ini_set('pcre.jit', '0');
        set_error_handler(static fn (): bool => true);

        try {
            $result = preg_match($pattern, $subject);
        } finally {
            restore_error_handler();
            \ini_set('pcre.jit', $jit);
        }

        return false === $result ? 'error: '.preg_last_error_msg() : $result;
    }
}
