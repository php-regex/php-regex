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

use PHPRegex\Generator\TestCaseGenerator;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every case the generator hands out is one the running engine agrees
 * with: preg_match() gives 1 on each matching case and 0 on each
 * non-matching one.
 */
final class CheckedTestCasesTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_every_case_is_what_preg_match_says(string $pattern): void
    {
        $cases = $this->cases($pattern);

        foreach ($cases['matching'] as $subject) {
            $this->assertSame(1, preg_match($pattern, $subject), $pattern.' does not match '.json_encode($subject));
        }

        foreach ($cases['non_matching'] as $subject) {
            $this->assertSame(0, preg_match($pattern, $subject), $pattern.' matches '.json_encode($subject));
        }

        $this->assertNotSame([], $cases['matching'], $pattern);
        $this->assertNotSame([], $cases['non_matching'], $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'an alternation' => ['pattern' => '/foo|bar/'];
        yield 'anchored digits' => ['pattern' => '/^\d{3}$/'];
        yield 'an email shape' => ['pattern' => '/^[a-z]+@[a-z]+\.com$/'];
        yield 'an optional letter' => ['pattern' => '/colou?r/'];
        yield 'a lookahead' => ['pattern' => '/^(?=\d)\w+$/'];
        yield 'a backreference' => ['pattern' => '/^(a|b)\1$/'];
        yield 'a caseless literal' => ['pattern' => '/^abc$/i'];
        yield 'a multibyte class' => ['pattern' => '/^[éè]+$/u'];
        yield 'extended mode' => ['pattern' => '/^ a b c $/x'];
        yield 'a counted repeat' => ['pattern' => '/\d{3}-\d{4}/'];
    }

    #[Test]
    public function test_a_case_the_engine_contradicts_is_dropped(): void
    {
        $this->assertSame(1, preg_match('/foo|bar/', 'bar'));

        $this->assertNotContains('bar', $this->cases('/foo|bar/')['non_matching']);
    }

    /**
     * "a*" matches the empty string at the start of every subject: no
     * string is a non-matching case.
     */
    #[Test]
    public function test_a_pattern_that_matches_every_subject_has_no_non_matching_case(): void
    {
        $this->assertSame([], $this->cases('/a*/')['non_matching']);
    }

    #[Test]
    public function test_a_pattern_the_engine_refuses_has_no_case(): void
    {
        // A lookbehind of unbounded length: it parses, PCRE refuses it.
        $pattern = '/(?<=b+a)x/';
        $this->assertNotNull((new PcreEngine())->compile($pattern));

        $this->assertSame(['matching' => [], 'non_matching' => []], $this->cases($pattern));
    }

    /**
     * @return array{matching: array<string>, non_matching: array<string>}
     */
    private function cases(string $pattern): array
    {
        return Regex::create(['cache' => null, 'runtime_pcre_validation' => false])->parse($pattern)->accept(new TestCaseGenerator());
    }
}
