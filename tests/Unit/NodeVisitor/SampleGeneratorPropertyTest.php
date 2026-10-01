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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Generator\SampleGenerationException;
use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A sample for "\p{...}" must be a character with that property: most
 * properties hold no ASCII letter, digit or punctuation mark, which is all
 * the generator used to produce. PHP decides every match below.
 */
final class SampleGeneratorPropertyTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_sample_has_the_property(string $pattern): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), $pattern);

        $regex = Regex::create(['cache' => null]);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $sample = $regex->generate($pattern);

            $this->assertSame(1, preg_match($pattern, $sample), \sprintf('%s does not match the sample %s.', $pattern, json_encode($sample)));
        }
    }

    #[Test]
    public function test_a_property_no_character_can_have_still_gives_a_sample(): void
    {
        // The surrogates have no UTF-8 form, so no subject matches: the
        // visitor guesses, and generate() says it found no sample. A name
        // PCRE does not know, built by hand, makes it refuse the probe.
        $surrogates = Regex::create(['cache' => null])->parse('/\\p{Cs}/u');
        $this->assertNotSame('', $surrogates->accept(new SampleGenerator()));

        try {
            Regex::create(['cache' => null])->generate('/\\p{Cs}/u');
            self::fail('A sample was given for a property no character has.');
        } catch (SampleGenerationException $e) {
            $this->assertSame(ErrorCode::GenerateNoMatch, $e->getErrorCode());
        }

        $tree = new RegexNode(new UnicodePropNode('{NoSuchProperty}', true, 0, 17), 'u', '/', 0, 17);
        $this->assertNotSame('', $tree->accept(new SampleGenerator()));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'script' => ['pattern' => '/^\\p{Greek}$/u'];
        yield 'script by its code' => ['pattern' => '/^\\p{sc=Cyrl}$/u'];
        yield 'Han' => ['pattern' => '/^\\p{Han}+$/u'];
        yield 'Arabic' => ['pattern' => '/^\\p{Arabic}$/u'];
        yield 'uppercase letter' => ['pattern' => '/^\\p{Lu}$/u'];
        yield 'negated letter' => ['pattern' => '/^\\P{L}$/u'];
        yield 'negated with a caret' => ['pattern' => '/^\\p{^Lu}$/u'];
        yield 'decimal digit' => ['pattern' => '/^\\p{Nd}$/u'];
        yield 'space separator' => ['pattern' => '/^\\p{Zs}$/u'];
        yield 'control' => ['pattern' => '/^\\p{Cc}$/u'];
        yield 'math symbol' => ['pattern' => '/^\\p{Sm}$/u'];
        yield 'mark' => ['pattern' => '/^\\p{Mn}$/u'];
        yield 'one-letter form' => ['pattern' => '/^\\pN$/u'];
        yield 'special category' => ['pattern' => '/^\\p{Xsp}$/u'];
        yield 'bidi class' => ['pattern' => '/^\\p{Bidi_Class=AL}$/u'];
        yield 'binary property' => ['pattern' => '/^\\p{Emoji}$/u'];
        yield 'in a class' => ['pattern' => '/^[\\p{Greek}\\p{Hebrew}]$/u'];
        yield 'without UTF mode' => ['pattern' => '/^\\p{Ll}$/'];
        yield 'Latin-1 letter without UTF mode' => ['pattern' => '/^\\p{Lu}\\P{Ll}$/'];
    }
}
