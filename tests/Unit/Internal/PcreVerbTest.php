<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Internal\PcreVerb;
use RegexParser\Node\GroupType;

/**
 * Telling apart the four things "(*...)" can hold.
 */
final class PcreVerbTest extends TestCase
{
    #[Test]
    #[DataProvider('provideBacktrackingVerbs')]
    public function test_a_backtracking_verb_is_kept_as_it_is(string $text, string $name): void
    {
        $verb = PcreVerb::read($text);

        $this->assertSame($name, $verb->name);
        $this->assertNotInstanceOf(GroupType::class, $verb->assertion);
        $this->assertNull($verb->matchLimit);
        $this->assertFalse($verb->isScriptRun());
    }

    /**
     * @return iterable<string, array{text: string, name: string}>
     */
    public static function provideBacktrackingVerbs(): iterable
    {
        yield 'fail' => ['text' => 'FAIL', 'name' => 'FAIL'];
        yield 'skip' => ['text' => 'SKIP', 'name' => 'SKIP'];
        yield 'a named mark' => ['text' => 'MARK:here', 'name' => 'MARK:here'];

        // "(*:name)" and "(*=name)" are shorthands for a mark.
        yield 'a mark written short' => ['text' => ':here', 'name' => 'MARK:here'];
        yield 'a mark written with an equals sign' => ['text' => '=here', 'name' => 'MARK=here'];

        // PCRE knows assertions and script runs in lowercase only: any other
        // spelling is a verb it does not know.
        yield 'a lookahead not in lowercase' => ['text' => 'PLA:foo', 'name' => 'PLA:foo'];
        yield 'a script run not in lowercase' => ['text' => 'SR:foo', 'name' => 'SR:foo'];
    }

    #[Test]
    #[DataProvider('provideAssertions')]
    public function test_an_alphabetic_assertion_stands_for_a_group(string $text, GroupType $group, string $payload): void
    {
        $verb = PcreVerb::read($text);

        $this->assertSame($group, $verb->assertion);
        $this->assertSame($payload, $verb->payload);
    }

    /**
     * @return iterable<string, array{text: string, group: GroupType, payload: string}>
     */
    public static function provideAssertions(): iterable
    {
        yield 'a lookahead, short' => [
            'text' => 'pla:foo',
            'group' => GroupType::T_GROUP_LOOKAHEAD_POSITIVE,
            'payload' => 'foo',
        ];
        yield 'a lookahead, spelled out' => [
            'text' => 'positive_lookahead:foo',
            'group' => GroupType::T_GROUP_LOOKAHEAD_POSITIVE,
            'payload' => 'foo',
        ];
        yield 'a negative lookbehind' => [
            'text' => 'nlb:foo',
            'group' => GroupType::T_GROUP_LOOKBEHIND_NEGATIVE,
            'payload' => 'foo',
        ];
        yield 'an atomic group' => [
            'text' => 'atomic:a+',
            'group' => GroupType::T_GROUP_ATOMIC,
            'payload' => 'a+',
        ];
    }

    #[Test]
    public function test_a_match_limit_carries_a_number(): void
    {
        $verb = PcreVerb::read('LIMIT_MATCH=4096');

        $this->assertSame(4096, $verb->matchLimit);
        $this->assertNotInstanceOf(GroupType::class, $verb->assertion);
    }

    #[Test]
    #[DataProvider('provideScriptRuns')]
    public function test_a_script_run_carries_a_sub_pattern(string $text, string $payload): void
    {
        $verb = PcreVerb::read($text);

        $this->assertTrue($verb->isScriptRun());
        $this->assertSame($payload, $verb->payload);
    }

    /**
     * @return iterable<string, array{text: string, payload: string}>
     */
    public static function provideScriptRuns(): iterable
    {
        yield 'spelled out' => ['text' => 'script_run:\d+', 'payload' => '\d+'];
        yield 'short' => ['text' => 'sr:\d+', 'payload' => '\d+'];
    }

    #[Test]
    public function test_an_atomic_script_run_carries_an_atomic_sub_pattern(): void
    {
        foreach (['asr:\\d+', 'atomic_script_run:\\d+'] as $text) {
            $verb = PcreVerb::read($text);

            $this->assertTrue($verb->isScriptRun(), $text);
            $this->assertTrue($verb->atomicScriptRun, $text);
            $this->assertSame('\\d+', $verb->payload, $text);
        }

        $this->assertFalse(PcreVerb::read('sr:\\d+')->atomicScriptRun);
    }

    #[Test]
    public function test_a_script_run_takes_an_argument_in_lowercase_only(): void
    {
        foreach (['sr', 'script_run', 'asr', 'atomic_script_run'] as $name) {
            $this->assertTrue(PcreVerb::takesArgument($name), $name);
        }

        $this->assertFalse(PcreVerb::takesArgument('SR'));
        $this->assertFalse(PcreVerb::takesArgument('ASR'));
    }

    #[Test]
    public function test_a_script_run_with_nothing_in_it_is_a_plain_verb(): void
    {
        $verb = PcreVerb::read('sr:');

        $this->assertFalse($verb->isScriptRun());
        $this->assertSame('sr:', $verb->name);
    }
}
