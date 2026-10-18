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

use PHPRegex\Generator\SampleGenerationException;
use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The generator package stands alone: SampleGenerator::generate() checks
 * its sample against the running engine, as the facade's generate() does,
 * without the facade.
 */
final class VerifiedSampleTest extends TestCase
{
    use PhpUnitForwardCompat;

    /**
     * Oracle: preg_match('/(?!^)a/', 'a') is 0 and 'aa' is 1. The tree
     * alone draws "a"; the engine asks for text before it.
     */
    #[Test]
    public function test_a_sample_the_tree_alone_gets_wrong_is_checked_by_the_engine(): void
    {
        $parser = RegexParser::create();
        $this->assertSame('a', $parser->parse('/(?!^)a/')->accept(new SampleGenerator()));
        $this->assertSame(0, preg_match('/(?!^)a/', 'a'));

        $this->assertSame(1, preg_match('/(?!^)a/', (new SampleGenerator())->generate('/(?!^)a/')));
    }

    #[Test]
    #[DataProvider('provideMatched')]
    public function test_the_sample_is_one_the_engine_matches(string $pattern): void
    {
        $generator = new SampleGenerator();
        for ($draw = 0; $draw < 20; $draw++) {
            $this->assertSame(1, preg_match($pattern, $generator->generate($pattern)), $pattern);
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideMatched(): iterable
    {
        yield 'word boundaries' => ['pattern' => '/\bword\b/'];
        yield 'condition with one valid branch' => ['pattern' => '/(?(?<!foo)cat|bar)/'];
        yield 'backreference' => ['pattern' => '/(a|b)\1/'];
        yield 'star delimiter' => ['pattern' => '*b\*c*'];
    }

    #[Test]
    public function test_a_pattern_nothing_matches_has_no_sample(): void
    {
        try {
            (new SampleGenerator())->generate('/a+(*FAIL)/');
            self::fail('A sample was given for a pattern nothing matches.');
        } catch (SampleGenerationException $e) {
            $this->assertSame(ErrorCode::GenerateNoMatch, $e->errorCode);
            $this->assertSame('No sample matching /a+(*FAIL)/ was found: the pattern may match nothing, or its assertions ask for more than the samples give.', $e->getMessage());
        }
    }

    /**
     * Each check runs to the backtrack limit; eight samples the engine gave
     * up on end the search, and none is padded.
     */
    #[Test]
    public function test_the_samples_the_engine_gave_up_on_are_named(): void
    {
        try {
            (new SampleGenerator())->generate('/(?:\w|\w){25}\z!/');
            self::fail('A sample was given for a pattern nothing matches.');
        } catch (SampleGenerationException $e) {
            $this->assertSame('No sample matching /(?:\w|\w){25}\z!/ was found: the engine gave up checking 8 of the samples (Backtrack limit exhausted).', $e->getMessage());
        }
    }

    #[Test]
    public function test_a_pattern_the_parser_judges_invalid_has_no_sample(): void
    {
        $this->expectException(SampleGenerationException::class);
        $this->expectExceptionMessageIsOrContains('No sample matching /a\j/ can be drawn: ');

        (new SampleGenerator())->generate('/a\j/');
    }

    #[Test]
    public function test_the_parser_given_judges_the_pattern(): void
    {
        $parser = RegexParser::create();

        $this->assertSame(1, preg_match('/\d{3}-[A-Z]{2}/', (new SampleGenerator())->generate('/\d{3}-[A-Z]{2}/', $parser)));
    }

    #[Test]
    public function test_a_seed_makes_the_checked_sample_reproducible(): void
    {
        $generator = new SampleGenerator();
        $generator->setSeed(42);
        $first = $generator->generate('/[A-Z]{3}-\d{4}/');
        $generator->setSeed(42);

        $this->assertSame($first, $generator->generate('/[A-Z]{3}-\d{4}/'));
    }
}
