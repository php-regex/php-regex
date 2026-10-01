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

namespace PHPRegex\Tests\Unit\ReDoS\Proven;

use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosWitness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One canonical form for a witness: a PHP double-quoted literal with every
 * byte outside printable ASCII escaped. Raw bytes only come from build().
 *
 * Each pattern below fails preg_match() at 19 pumps of its single character
 * followed by "!" on PCRE2 10.49, so the pump is that character.
 */
final class RedosWitnessRenderingTest extends TestCase
{
    #[Test]
    #[DataProvider('provideEscapedPumps')]
    public function test_witness_pump_renders_escaped(string $pattern, string $rawPump, string $escapedPump): void
    {
        $witness = $this->witnessOf($pattern);

        $this->assertMatchesRegularExpression('/^(?:'.preg_quote($rawPump, '/').')+$/', $witness->pump);
        $this->assertMatchesRegularExpression('/^(?:'.preg_quote($escapedPump, '/').')+$/', $witness->toArray()['pump']);
        $this->assertStringContainsString('"'.$escapedPump, $witness->render());
        $this->assertStringContainsString('" x n', $witness->render());
        $this->assertDoesNotMatchRegularExpression('/[^\x20-\x7E]/', $witness->render());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideEscapedPumps(): iterable
    {
        yield 'high byte without u' => ['/(\xFF+)+$/', "\xFF", '\xFF'];
        // Always \x00, never \0: "\01" is chr(1) in PHP.
        yield 'nul byte' => ['/(\x00+)+$/', "\0", '\x00'];
        yield 'escape control character' => ['/(\x1B+)+$/', "\x1B", '\x1B'];
        // Under /u a code point is written the PHP way, \u{..}: pasted into PHP it gives
        // the same bytes (PHP reads "\x{202E}" as eight literal characters).
        yield 'right-to-left override under u' => ['/(\x{202E}+)+$/u', "\u{202E}", '\u{202E}'];
        yield 'newline' => ['/(\n+)+$/', "\n", '\n'];
        yield 'tab' => ['/(\t+)+$/', "\t", '\t'];
    }

    /**
     * The canonical form is a PHP double-quoted literal: read back by PHP,
     * each part gives the raw bytes again, including the characters a PHP
     * literal must escape, a NUL byte followed by a digit ("\01" is chr(1)
     * in PHP, not "\0" . "1") and code points under /u.
     */
    #[Test]
    #[DataProvider('provideWitnessPatterns')]
    public function test_witness_parts_read_back_as_the_raw_bytes(string $pattern): void
    {
        $witness = $this->witnessOf($pattern);
        $raw = ['prefix' => $witness->prefix, 'pump' => $witness->pump, 'suffix' => $witness->suffix];

        foreach ($witness->toArray() as $part => $literal) {
            $this->assertMatchesRegularExpression('/^[\x20-\x7E]*$/', $literal, $part);
            $this->assertSame($raw[$part], self::readPhpLiteral($literal), $part.' of '.$pattern);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideWitnessPatterns(): iterable
    {
        yield 'high byte' => ['/(\xFF+)+$/'];
        yield 'escape control character' => ['/(\x1B+)+$/'];
        // Pump "\0" . "1": (?:\x001)+ nested, fails at 19 pumps on 10.49.
        yield 'nul byte before a digit' => ['/((?:\x001)+)+$/'];
        yield 'dollar sign' => ['/(\$+)+$/'];
        yield 'double quote' => ['/("+)+$/'];
        yield 'backslash' => ['/(\\\\+)+$/'];
        yield 'dot before a newline' => ['/(.+)+$/'];
        // Code points under /u, each failing at 19 pumps on 10.49.
        yield 'right-to-left override under u' => ['/(\x{202E}+)+$/u'];
        yield 'e acute under u' => ['/(\w|é)+$/u'];
        yield 'no printable ascii under u' => ['/(\x{100}|[\x{100}-\x{200}])+$/u'];
    }

    #[Test]
    public function test_witness_render_has_the_canonical_shape(): void
    {
        $witness = new RedosWitness('aaa', 'a', '!', false);

        $this->assertSame('"aaa" . "a" x n . "!"', $witness->render());
        $this->assertSame(['prefix' => 'aaa', 'pump' => 'a', 'suffix' => '!'], $witness->toArray());
        $this->assertSame('aaaaaaa!', $witness->build(4));
    }

    /**
     * Empty parts are left out of render(); toArray() keeps every part,
     * unquoted.
     *
     * @param array{prefix: string, pump: string, suffix: string} $parts
     */
    #[Test]
    #[DataProvider('provideCanonicalForms')]
    public function test_witness_render_canonical_forms(RedosWitness $witness, string $rendered, array $parts): void
    {
        $this->assertSame($rendered, $witness->render());
        $this->assertSame($parts, $witness->toArray());
    }

    /**
     * @return iterable<string, array{RedosWitness, string, array{prefix: string, pump: string, suffix: string}}>
     */
    public static function provideCanonicalForms(): iterable
    {
        yield 'empty prefix' => [new RedosWitness('', 'a', '!', false), '"a" x n . "!"', ['prefix' => '', 'pump' => 'a', 'suffix' => '!']];
        yield 'empty suffix' => [new RedosWitness('aaa', 'a', '', false), '"aaa" . "a" x n', ['prefix' => 'aaa', 'pump' => 'a', 'suffix' => '']];
        yield 'pump only' => [new RedosWitness('', 'a', '', false), '"a" x n', ['prefix' => '', 'pump' => 'a', 'suffix' => '']];
        yield 'nul byte before a digit' => [new RedosWitness('', "\0", '1', false), '"\x00" x n . "1"', ['prefix' => '', 'pump' => '\x00', 'suffix' => '1']];
        yield 'code point under u' => [new RedosWitness('', "\u{202E}", '!', true), '"\u{202E}" x n . "!"', ['prefix' => '', 'pump' => '\u{202E}', 'suffix' => '!']];
        yield 'high byte without u' => [new RedosWitness('', "\xFF", "\n", false), '"\xFF" x n . "\n"', ['prefix' => '', 'pump' => '\xFF', 'suffix' => '\n']];
        yield 'characters a php literal escapes' => [new RedosWitness('$', '"', '\\', false), '"\$" . "\"" x n . "\\\\"', ['prefix' => '\$', 'pump' => '\"', 'suffix' => '\\\\']];
    }

    #[Test]
    public function test_witness_build_returns_raw_bytes(): void
    {
        $witness = new RedosWitness("\x1B", "\xFF", "\0", false);

        $this->assertSame("\x1B\xFF\xFF\0", $witness->build(2));
        $this->assertSame("\x1B\0", $witness->build(0));
    }

    /**
     * @param non-empty-string $escapedPump
     */
    #[Test]
    #[DataProvider('provideEscapedPumps')]
    public function test_analysis_json_never_fails_and_carries_no_raw_byte(string $pattern, string $rawPump, string $escapedPump): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $json = json_encode($analysis, \JSON_THROW_ON_ERROR);

        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F-\xFF]/', $json);
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded['witness']);
        $this->assertSame(['prefix', 'pump', 'suffix'], array_keys($decoded['witness']));
        $this->assertIsString($decoded['witness']['pump']);
        $this->assertStringNotContainsString($rawPump, (string) $decoded['witness']['pump']);
        $this->assertStringStartsWith($escapedPump, $decoded['witness']['pump']);
    }

    /**
     * Determinism: two analyses give the same witness.
     */
    #[Test]
    public function test_witness_is_the_same_on_every_run(): void
    {
        $first = $this->witnessOf('/(\w+\s?)+$/');
        $second = (new RedosAnalyzer())->analyze('/(\w+\s?)+$/')->witness;

        $this->assertInstanceOf(RedosWitness::class, $second);
        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertSame($first->unicode, $second->unicode);
    }

    /**
     * The representative character is the smallest printable ASCII code
     * point of the ambiguous set, else its smallest code point.
     */
    #[Test]
    #[DataProvider('provideRepresentativeCharacters')]
    public function test_witness_pump_uses_the_smallest_printable_character(string $pattern, string $character): void
    {
        $witness = $this->witnessOf($pattern);

        $this->assertMatchesRegularExpression('/^(?:'.preg_quote($character, '/').')+$/u', $witness->pump);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRepresentativeCharacters(): iterable
    {
        // \w ∩ \d = [0-9]: 1…1! fails at 19 pumps on 10.49.
        yield 'digits inside word characters' => ['/(\w|\d)+$/', '0'];
        // [b-z] ∩ [m-q] = [m-q].
        yield 'intersection of two ranges' => ['/([b-z]|[m-q])+$/', 'm'];
        yield 'single letter' => ['/(a+)+$/', 'a'];
        // Only é is in both branches under /u.
        yield 'e acute under u' => ['/(\w|é)+$/u', 'é'];
        // No printable ASCII in the set: its smallest code point.
        yield 'no printable ascii under u' => ['/(\x{100}|[\x{100}-\x{200}])+$/u', "\u{100}"];
        // Space is taken last among printable ASCII: [^b] gives "!" (!…!b fails at 19 pumps).
        yield 'negated class' => ['/([^b]+)+$/', '!'];
        yield 'space and exclamation mark' => ['/([ !]+)+$/', '!'];
        // Space only when the class has no other printable ASCII: " … !" fails at 19 pumps.
        yield 'space only' => ['/( +)+$/', ' '];
    }

    /**
     * The rejecting character follows the same rule: after a…a, any
     * character but "a" rejects, and "!" is the smallest printable one other
     * than space (a…a! fails at 19 pumps on 10.49).
     */
    #[Test]
    public function test_witness_suffix_uses_the_smallest_printable_character(): void
    {
        $this->assertSame('!', $this->witnessOf('/(a+)+$/')->suffix);
    }

    #[Test]
    public function test_witness_records_the_unicode_mode(): void
    {
        $this->assertTrue($this->witnessOf('/(\w|é)+$/u')->unicode);
        $this->assertFalse($this->witnessOf('/(a+)+$/')->unicode);
    }

    private function witnessOf(string $pattern): RedosWitness
    {
        $witness = (new RedosAnalyzer())->analyze($pattern)->witness;
        $this->assertInstanceOf(RedosWitness::class, $witness, $pattern.' has no witness');

        return $witness;
    }

    /**
     * Reads one part of the canonical form the way PHP itself reads a
     * double-quoted literal. The tokenizer first proves the text is one
     * constant string, with no interpolation and no code, so that the eval()
     * below can only produce that string.
     */
    private static function readPhpLiteral(string $literal): string
    {
        $tokens = token_get_all('<?php "'.$literal.'";');
        self::assertCount(3, $tokens, 'not a single constant string: '.$literal);
        self::assertIsArray($tokens[1]);
        self::assertSame(\T_CONSTANT_ENCAPSED_STRING, $tokens[1][0], 'not a constant string: '.$literal);
        self::assertSame('"'.$literal.'"', $tokens[1][1]);

        $value = eval('return "'.$literal.'";');
        self::assertIsString($value);

        return $value;
    }
}
