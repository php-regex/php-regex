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

namespace PHPRegex\Tests\Unit\Cli;

use PHPRegex\Cli\Command\RedosArguments;
use PHPRegex\Cli\Command\RedosInput;
use PHPRegex\Redos\RedosWitness;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedosInputTest extends TestCase
{
    #[Test]
    public function test_the_input_given_is_repeated_between_the_prefix_and_the_suffix(): void
    {
        $input = RedosInput::from(new RedosArguments('/a/', input: 'ab', repeat: 3, prefix: '<', suffix: '>'), Regex::create());

        $this->assertInstanceOf(RedosInput::class, $input);
        $this->assertSame('ab', $input->value);
        $this->assertSame('user', $input->source);
        $this->assertNull($input->note);
        $this->assertSame('<ababab>', $input->subject);
    }

    #[Test]
    public function test_an_input_file_is_read_whole(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'regex-redos-');
        $this->assertIsString($file);
        file_put_contents($file, "a\nb");

        try {
            $input = RedosInput::from(new RedosArguments('/a/', inputFile: $file, repeat: 2), Regex::create());
        } finally {
            unlink($file);
        }

        $this->assertInstanceOf(RedosInput::class, $input);
        $this->assertSame("a\nb", $input->value);
        $this->assertSame('file', $input->source);
        $this->assertSame("a\nba\nb", $input->subject);
    }

    #[Test]
    public function test_an_input_file_that_cannot_be_read_is_refused(): void
    {
        $missing = sys_get_temp_dir().'/regex-redos-missing-'.bin2hex(random_bytes(4));

        $this->assertSame('Input file not readable: '.$missing, RedosInput::from(new RedosArguments('/a/', inputFile: $missing), Regex::create()));
        $this->assertSame('Input file not readable: '.sys_get_temp_dir(), RedosInput::from(new RedosArguments('/a/', inputFile: sys_get_temp_dir()), Regex::create()));
    }

    #[Test]
    public function test_without_an_input_one_is_generated_from_the_culprit_of_the_pattern(): void
    {
        $input = RedosInput::from(new RedosArguments('/(a+)+$/'), Regex::create());

        $this->assertInstanceOf(RedosInput::class, $input);
        $this->assertSame('auto', $input->source);
        $this->assertNull($input->note);
        $this->assertNotSame('', $input->value);
        $this->assertSame($input->value, $input->subject);
    }

    /**
     * A proven verdict carries its witness, the input that drives the worst
     * case: the generated input is that witness, which holds the literal
     * every match needs. "a" x 50 . "!" holds no "b", and PCRE2 rejects it
     * for /(a+)+b/ before it backtracks; the witness runs into the backtrack
     * limit (PHP 8.4.27, PCRE2 10.49, no JIT).
     */
    #[Test]
    public function test_a_proven_verdict_runs_on_its_witness(): void
    {
        $regex = Regex::create();
        $input = RedosInput::from(new RedosArguments('/(a+)+b/'), $regex);

        $this->assertInstanceOf(RedosInput::class, $input);
        $this->assertSame('auto', $input->source);
        $witness = $regex->redos('/(a+)+b/')->witness;
        $this->assertInstanceOf(RedosWitness::class, $witness);
        $this->assertSame($witness->build(32), $input->value);
        $this->assertStringContainsString('b', $input->value);
    }

    #[Test]
    public function test_a_pattern_without_a_culprit_runs_on_the_default_input(): void
    {
        foreach (['/abc/', '/a(/'] as $pattern) {
            $input = RedosInput::from(new RedosArguments($pattern, repeat: 2), Regex::create());

            $this->assertInstanceOf(RedosInput::class, $input);
            $this->assertSame('a!', $input->value);
            $this->assertSame('default', $input->source);
            $this->assertSame('Auto input unavailable; using default.', $input->note);
            $this->assertSame('a!a!', $input->subject);
        }
    }

    /**
     * The input repeated weighs 8 MiB at most: printed with --show-input, a
     * subject of bytes to escape takes about five times its size, which
     * stays within PHP's default memory_limit of 128M at 8 MiB and goes past
     * it at 16 MiB. A count past the limit is refused before the subject is
     * built: ten billion copies of one byte would take 10 GB.
     */
    #[Test]
    #[DataProvider('provideRepeatsPastTheLimit')]
    public function test_a_repeat_past_the_limit_is_refused_before_the_subject_is_built(string $input, int $repeat, string $error): void
    {
        $this->assertSame($error, RedosInput::from(new RedosArguments('/a/', input: $input, repeat: $repeat), Regex::create()));
    }

    /**
     * @return iterable<string, array{input: string, repeat: int, error: string}>
     */
    public static function provideRepeatsPastTheLimit(): iterable
    {
        yield 'the largest integer of copies of a byte' => ['input' => 'a', 'repeat' => \PHP_INT_MAX, 'error' => self::pastTheLimit(\PHP_INT_MAX, 1, 8_388_608)];
        yield 'one copy of a byte past the limit' => ['input' => 'a', 'repeat' => 8_388_609, 'error' => self::pastTheLimit(8_388_609, 1, 8_388_608)];
        yield 'one copy of two bytes past the limit' => ['input' => 'ab', 'repeat' => 4_194_305, 'error' => self::pastTheLimit(4_194_305, 2, 4_194_304)];
        yield 'the largest count of a long input' => ['input' => str_repeat('x', 8_388_609), 'repeat' => \PHP_INT_MAX, 'error' => self::pastTheLimit(\PHP_INT_MAX, 8_388_609, 0)];
    }

    #[Test]
    public function test_a_generated_input_is_held_to_the_same_limit(): void
    {
        $generated = RedosInput::from(new RedosArguments('/(a+)+$/'), Regex::create());
        $this->assertInstanceOf(RedosInput::class, $generated);
        $length = \strlen($generated->value);

        $this->assertSame(self::pastTheLimit(\PHP_INT_MAX, $length, intdiv(8_388_608, $length)), RedosInput::from(new RedosArguments('/(a+)+$/', repeat: \PHP_INT_MAX), Regex::create()));
    }

    /**
     * The largest count builds a repeated input of 8 MiB at most, between
     * the prefix and the suffix, which are not repeated; an empty input
     * repeated any number of times is empty.
     */
    #[Test]
    #[DataProvider('provideLargestRepeats')]
    public function test_the_largest_repeat_is_built(string $input, int $repeat, int $length): void
    {
        $built = RedosInput::from(new RedosArguments('/a/', input: $input, repeat: $repeat, prefix: '<', suffix: '>'), Regex::create());

        $this->assertInstanceOf(RedosInput::class, $built);
        $this->assertSame($length, \strlen($built->subject));
        $this->assertStringStartsWith('<'.$input, $built->subject);
        $this->assertStringEndsWith($input.'>', $built->subject);
    }

    /**
     * @return iterable<string, array{input: string, repeat: int, length: int}>
     */
    public static function provideLargestRepeats(): iterable
    {
        yield 'a byte' => ['input' => 'a', 'repeat' => 8_388_608, 'length' => 8_388_610];
        yield 'two bytes' => ['input' => 'ab', 'repeat' => 4_194_304, 'length' => 8_388_610];
        yield 'three bytes, the remainder left out' => ['input' => 'abc', 'repeat' => 2_796_202, 'length' => 8_388_608];
        yield 'an empty input' => ['input' => '', 'repeat' => \PHP_INT_MAX, 'length' => 2];
    }

    #[Test]
    public function test_the_preview_escapes_the_subject_and_cuts_it_at_its_limit(): void
    {
        $input = new RedosInput('x', 'user', null, str_repeat('a', 130)."\n");

        $this->assertSame(str_repeat('a', 120).'...', $input->preview());
        $this->assertSame(str_repeat('a', 130).'\n', $input->preview(null));
        $this->assertSame(str_repeat('a', 130).'\n', $input->preview(132));
        $this->assertSame(str_repeat('a', 130).'\n...', (new RedosInput('x', 'user', null, str_repeat('a', 130)."\n!"))->preview(132));
    }

    private static function pastTheLimit(int $repeat, int $length, int $largest): string
    {
        return \sprintf('--repeat %d repeats the %d-byte input past 8 MiB (8388608 bytes), the most it may weigh: printed with --show-input, a subject takes up to five times its size, which must fit in PHP\'s default memory_limit of 128M. The largest count for this input is %d.', $repeat, $length, $largest);
    }
}
