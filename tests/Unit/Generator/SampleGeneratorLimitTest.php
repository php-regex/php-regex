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

namespace PHPRegex\Tests\Unit\Generator;

use PHPRegex\Generator\Exception\SampleGenerationException;
use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\EngineProbe;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SampleGeneratorLimitTest extends TestCase
{
    use PcreGates;
    use PhpUnitForwardCompat;

    #[DataProvider('provideEntryPoints')]
    public function test_capture_doubling_is_rejected(string $entry): void
    {
        $pattern = '/'.self::doublingChain(21).'/';
        $this->assertTrue(EngineProbe::compiles($pattern));
        $this->expectException(SampleGenerationException::class);
        $this->expectExceptionMessageIsOrContains('No sample was built: it would pass 1048576 bytes.');

        match ($entry) {
            'facade' => Regex::create()->generate($pattern),
            'generator' => (new SampleGenerator())->generate($pattern),
            'visitor' => RegexParser::create()->parse($pattern)->accept(new SampleGenerator()),
            default => $this->fail('No entry point '.$entry.'.'),
        };
    }

    /**
     * @return iterable<string, array{entry: string}>
     */
    public static function provideEntryPoints(): iterable
    {
        yield 'facade' => ['entry' => 'facade'];
        yield 'generator' => ['entry' => 'generator'];
        yield 'visitor' => ['entry' => 'visitor'];
    }

    /**
     * Each layout of a sample is joined and tried only when it fits: one
     * past the limit is skipped, not refused, so a shorter layout after it
     * is still tried. With an unmatched attempt of 1,048,575 bytes,
     * "attempt.padding" fits where "padding.attempt.padding" does not.
     */
    public function test_layouts_past_the_limit_are_skipped_not_refused(): void
    {
        $method = new \ReflectionMethod(SampleGenerator::class, 'within');
        $within = function (array ...$layouts) use ($method): array {
            $joined = $method->invoke(null, ...$layouts);
            $this->assertInstanceOf(\Generator::class, $joined);

            return iterator_to_array($joined, false);
        };
        $attempt = str_repeat('a', 1_048_575);

        $layouts = $within(['b', $attempt, 'b'], [$attempt, 'b'], ['b', $attempt]);

        $this->assertSame([$attempt.'b', 'b'.$attempt], $layouts);
        $this->assertSame(1_048_576, \strlen($attempt.'b'));
        $this->assertSame([''], $within([str_repeat('a', 1_048_577)], []));
    }

    public function test_exact_byte_limit_is_allowed(): void
    {
        $pattern = '/'.self::doublingChain(19).'a/';
        $sample = (new SampleGenerator())->generate($pattern);

        $this->assertSame(1048576, \strlen($sample));
        $this->assertSame(1, preg_match($pattern, $sample));
    }

    public function test_one_byte_over_limit_is_rejected_even_before_accept(): void
    {
        $pattern = '/'.self::doublingChain(19).'aa(*ACCEPT)/';
        $this->assertTrue(EngineProbe::compiles($pattern));
        $this->expectException(SampleGenerationException::class);
        $this->expectExceptionMessageIsOrContains('No sample was built: it would pass 1048576 bytes.');
        RegexParser::create()->parse($pattern)->accept(new SampleGenerator());
    }

    public function test_oversized_intermediate_lookahead_is_rejected(): void
    {
        $pattern = '/(?='.self::doublingChain(21).')(*ACCEPT)/';
        $this->assertTrue(EngineProbe::compiles($pattern));
        $this->expectException(SampleGenerationException::class);
        $this->expectExceptionMessageIsOrContains('No sample was built: it would pass 1048576 bytes.');
        RegexParser::create()->parse($pattern)->accept(new SampleGenerator());
    }

    public function test_limit_counts_bytes_in_utf_mode(): void
    {
        $pattern = '/'.str_replace('(a)', '(é)', self::doublingChain(19)).'/u';
        $this->assertTrue(EngineProbe::compiles($pattern));
        $this->expectException(SampleGenerationException::class);
        $this->expectExceptionMessageIsOrContains('No sample was built: it would pass 1048576 bytes.');
        (new SampleGenerator())->generate($pattern);
    }

    /**
     * The draw "b" stands at the start, where "(?<!^)" fails: only text put
     * before it gives a match, as PCRE reads "ab" (not "ba" nor "aba").
     */
    public function test_a_draw_is_padded_before_when_only_text_before_it_matches(): void
    {
        $this->assertSame([0, 1, 0, 0], [preg_match('/(?<!^)b\z/', 'b'), preg_match('/(?<!^)b\z/', 'ab'), preg_match('/(?<!^)b\z/', 'ba'), preg_match('/(?<!^)b\z/', 'aba')]);

        $this->assertSame('ab', (new SampleGenerator())->generate('/(?<!^)b\z/'));
    }

    /**
     * The visitor fits a scanned group to the scan's body, asking the
     * engine whether the body holds: seeded, its draws are fixed text. A
     * PCRE2 before 10.45 reads no "(*scs:" and fits nothing.
     *
     * @param list<string> $samples
     */
    #[DataProvider('provideScannedGroups')]
    public function test_a_scanned_group_is_fitted_by_the_visitor(string $pattern, array $samples): void
    {
        $this->requiresPcre2('10.45', 'A substring scan');
        $ast = RegexParser::create(['cache' => null])->parse($pattern);
        $generator = new SampleGenerator();
        $drawn = [];
        foreach (array_keys($samples) as $seed) {
            $generator->setSeed($seed);
            $drawn[] = $ast->accept($generator);
        }

        $this->assertSame($samples, $drawn);
    }

    /**
     * @return iterable<string, array{pattern: string, samples: list<string>}>
     */
    public static function provideScannedGroups(): iterable
    {
        yield 'a literal body' => ['pattern' => '/^([a-z])([a-z]++)(#+)(*scs:(2)(ab.))$/', 'samples' => ['pabczd###', 'jabat##', 'jabcme##']];
        yield 'a lookahead body' => ['pattern' => '/^()(\w++)=(*scs:(2)(?=abc))(\w++)$/', 'samples' => ['abc_=a_', 'abc_a=_', 'abc_=a5']];
    }

    private static function doublingChain(int $steps): string
    {
        $pattern = '(a)';
        for ($group = 1; $group <= $steps; $group++) {
            $pattern .= '(\\g{'.$group.'}\\g{'.$group.'})';
        }

        return $pattern;
    }
}
