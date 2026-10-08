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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2 never normalizes Unicode, and neither does the library: "é" written
 * precomposed (U+00E9, NFC) and decomposed ("e" then U+0301, NFD) are two
 * different patterns, which match two different subjects. A normalization
 * slipped into any layer would turn one into the other.
 */
final class NormalizationInvariantTest extends TestCase
{
    private const PRECOMPOSED = "\u{E9}";

    private const DECOMPOSED = "e\u{301}";

    /**
     * Oracle, PHP 8.4.26 / PCRE2 10.49: a precomposed pattern never matches
     * a decomposed subject, nor the other way round, under "i" too.
     */
    #[Test]
    #[DataProvider('provideFlags')]
    public function test_the_engine_tells_the_two_forms_apart(string $flags): void
    {
        $this->assertSame(0, preg_match('/^'.self::PRECOMPOSED.'$/'.$flags, self::DECOMPOSED));
        $this->assertSame(0, preg_match('/^'.self::DECOMPOSED.'$/'.$flags, self::PRECOMPOSED));
        $this->assertSame(1, preg_match('/^'.self::PRECOMPOSED.'$/'.$flags, self::PRECOMPOSED));
        $this->assertSame(1, preg_match('/^'.self::DECOMPOSED.'$/'.$flags, self::DECOMPOSED));
    }

    /**
     * @return iterable<string, array{flags: string}>
     */
    public static function provideFlags(): iterable
    {
        yield 'utf' => ['flags' => 'u'];
        yield 'utf and caseless' => ['flags' => 'ui'];
    }

    #[Test]
    #[DataProvider('providePatterns')]
    public function test_a_pattern_prints_back_in_the_form_it_was_written(string $pattern): void
    {
        $printed = Regex::create(['cache' => null])->parse($pattern)->accept(new PatternPrinter());

        $this->assertSame(bin2hex($pattern), bin2hex($printed));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'decomposed' => ['pattern' => '/'.self::DECOMPOSED.'/u'];
        yield 'precomposed' => ['pattern' => '/'.self::PRECOMPOSED.'/u'];
        yield 'decomposed in a class' => ['pattern' => '/['.self::DECOMPOSED.']/u'];
        yield 'decomposed under i' => ['pattern' => '/'.self::DECOMPOSED.'/ui'];
    }

    #[Test]
    #[DataProvider('provideFlags')]
    public function test_the_solver_tells_the_two_forms_apart(string $flags): void
    {
        $left = '/'.self::PRECOMPOSED.'/'.$flags;
        $right = '/'.self::DECOMPOSED.'/'.$flags;

        $result = (new LanguageSolver())->equivalent($left, $right, new SolverOptions(matchMode: MatchMode::Full));

        $this->assertFalse($result->isEquivalent);
        $witness = $result->leftOnlyExample ?? $result->rightOnlyExample;
        $this->assertNotNull($witness);
        // The witness tells the patterns apart on the engine.
        $this->assertNotSame(preg_match('/^(?:'.self::PRECOMPOSED.')$/'.$flags, $witness), preg_match('/^(?:'.self::DECOMPOSED.')$/'.$flags, $witness));
    }
}
