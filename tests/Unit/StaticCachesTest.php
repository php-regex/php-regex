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

use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Linter\Rule\Support\LanguageQuestions;
use PHPRegex\Parser\Analysis\ComplexityScorer;
use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\Cache\StaticCaches;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\QuantifierCounts;
use PHPRegex\Parser\Validation\Validator;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The library's process-wide caches: each one bounded (a long-running
 * process parsing patterns by the thousand must not grow without end), and
 * all of them emptied by one call.
 */
final class StaticCachesTest extends TestCase
{
    private const BOUND = 1000;

    #[Test]
    public function test_clear_caches_empties_every_static_cache(): void
    {
        $parser = RegexParser::create(['cache' => new NullCache()]);

        $parser->validate('/\p{Latin}a{2,3}/u');
        $parser->parse('/a+/')->accept(new ComplexityScorer());
        $parser->parse('/\p{Latin}/')->accept(new SampleGenerator());
        $parser->parse('/\p{Linear_B}/u')->accept(new SampleGenerator());
        // \w, not a class of ranges: the model builds those itself and
        // never asks the class-set cache.
        (new RedosAnalyzer())->analyze('/(\w+)+$/');

        foreach (self::caches() as [$class, $property]) {
            $this->assertNotSame([], $this->read($class, $property), 'Not warmed: '.$class.'::$'.$property);
        }

        $parser->clearCaches();

        foreach (self::caches() as [$class, $property]) {
            $this->assertSame([], $this->read($class, $property), 'Not cleared: '.$class.'::$'.$property);
        }
    }

    /**
     * The lint rules that ask the solver keep one parser per PCRE2 release
     * judged: clearCaches() drops them with the rest.
     */
    #[Test]
    public function test_clear_caches_drops_the_parsers_of_the_lint_questions(): void
    {
        $parsers = new \ReflectionProperty(LanguageQuestions::class, 'parsers');
        $this->assertNotNull(LanguageQuestions::matchesNothing('/a/'));
        $this->assertContainsOnlyInstancesOf(RegexParser::class, (array) $parsers->getValue());
        $this->assertNotSame([], $parsers->getValue());

        RegexParser::create(['cache' => new NullCache()])->clearCaches();

        $this->assertSame([], $parsers->getValue());
    }

    /**
     * clearCaches() replaces it outright: 2.0 is the major.
     */
    #[Test]
    public function test_the_validator_only_clear_is_gone(): void
    {
        $this->assertFalse((new \ReflectionClass(RegexParser::class))->hasMethod('clearValidatorCaches'));
        $this->assertFalse((new \ReflectionClass(Regex::class))->hasMethod('clearValidatorCaches'));
    }

    /**
     * The validator's caches are emptied with every other one, by the one
     * call that reaches them all, not by a call of their own.
     */
    #[Test]
    public function test_the_shared_clear_empties_the_validator_caches(): void
    {
        $this->assertTrue(RegexParser::create(['cache' => new NullCache()])->validate('/\p{Latin}a{2,3}/u')->isValid);
        $this->assertNotSame([], $this->read(Validator::class, 'unicodePropCache'));
        $this->assertNotSame([], $this->read(QuantifierCounts::class, 'quantifierBoundsCache'));

        StaticCaches::clear();

        $this->assertSame([], $this->read(Validator::class, 'unicodePropCache'));
        $this->assertSame([], $this->read(QuantifierCounts::class, 'quantifierBoundsCache'));
    }

    /**
     * "a{1,0}", "a{1,1}" ... fifteen hundred counts, each its own key.
     */
    #[Test]
    public function test_the_validator_count_cache_is_bounded(): void
    {
        $this->write(QuantifierCounts::class, 'quantifierBoundsCache', []);
        $parser = RegexParser::create(['cache' => new NullCache()]);

        for ($max = 1; $max <= 1500; $max++) {
            $this->assertTrue($parser->validate('/a{1,'.$max.'}/')->isValid);
        }

        $count = \count($this->read(QuantifierCounts::class, 'quantifierBoundsCache'));
        $this->assertGreaterThan(0, $count);
        $this->assertLessThanOrEqual(self::BOUND, $count);
    }

    /**
     * "{1,0}", "{1,1}" ... fifteen hundred quantifiers, each its own key.
     */
    #[Test]
    public function test_the_complexity_cache_is_bounded(): void
    {
        $this->write(ComplexityScorer::class, 'unboundedQuantifierCache', []);
        $parser = RegexParser::create(['cache' => new NullCache()]);

        for ($max = 0; $max < 1500; $max++) {
            $parser->parse('/a{1,'.$max.'}/')->accept(new ComplexityScorer());
        }

        $count = \count($this->read(ComplexityScorer::class, 'unboundedQuantifierCache'));
        $this->assertGreaterThan(0, $count);
        $this->assertLessThanOrEqual(self::BOUND, $count);
    }

    /**
     * Oracle: PCRE2 matches property names loosely (case, spaces, "_" and
     * "-" ignored), so "\p{l A-t_in}" is "\p{Latin}" and every spelling is
     * a key of its own.
     */
    #[Test]
    public function test_the_property_sample_cache_is_bounded(): void
    {
        $this->assertSame(1, preg_match('/\p{l A-t_in}/', 'a'), 'The oracle refuses a loose spelling.');

        $this->write(SampleGenerator::class, 'propertySamples', []);
        $parser = RegexParser::create(['cache' => new NullCache()]);

        foreach (self::latinSpellings(1500) as $spelling) {
            $parser->parse('/\p{'.$spelling.'}/')->accept(new SampleGenerator());
        }

        $count = \count($this->read(SampleGenerator::class, 'propertySamples'));
        $this->assertGreaterThan(0, $count);
        $this->assertLessThanOrEqual(self::BOUND, $count);
    }

    /**
     * The ReDoS model's class sets, one per atom and modifiers: "[\x00-\x00]",
     * "[\x00-\x01]" ... fifteen hundred byte ranges, each its own key.
     */
    #[Test]
    public function test_the_class_set_cache_is_bounded(): void
    {
        $this->write(ClassSetProvider::class, 'sets', []);

        $queried = 0;
        for ($low = 0; $low < 256 && $queried < 1500; $low++) {
            for ($high = $low; $high < 256 && $queried < 1500; $high++) {
                $this->assertInstanceOf(CharSet::class, ClassSetProvider::query(\sprintf('[\x%02X-\x%02X]', $low, $high), false, ''));
                $queried++;
            }
        }

        $count = \count($this->read(ClassSetProvider::class, 'sets'));
        $this->assertGreaterThan(0, $count);
        $this->assertLessThanOrEqual(self::BOUND, $count);
    }

    /**
     * @return list<array{class-string, string}>
     */
    private static function caches(): array
    {
        return [
            [Validator::class, 'unicodePropCache'],
            [QuantifierCounts::class, 'quantifierBoundsCache'],
            [ComplexityScorer::class, 'unboundedQuantifierCache'],
            [SampleGenerator::class, 'propertySamples'],
            [SampleGenerator::class, 'codePointChunks'],
            [ClassSetProvider::class, 'sets'],
        ];
    }

    /**
     * "latin" spelled $count ways: each letter in either case, each gap
     * empty or holding "_", " " or "-".
     *
     * @return list<string>
     */
    private static function latinSpellings(int $count): array
    {
        $gaps = ['', '_', ' ', '-'];
        $spellings = [];
        foreach ($gaps as $a) {
            foreach ($gaps as $b) {
                foreach ($gaps as $c) {
                    foreach ($gaps as $d) {
                        for ($mask = 0; $mask < 32; $mask++) {
                            $letters = str_split('latin');
                            foreach ($letters as $position => $letter) {
                                if (0 !== ($mask & (1 << $position))) {
                                    $letters[$position] = strtoupper($letter);
                                }
                            }

                            $spellings[] = $letters[0].$a.$letters[1].$b.$letters[2].$c.$letters[3].$d.$letters[4];
                            if (\count($spellings) >= $count) {
                                return $spellings;
                            }
                        }
                    }
                }
            }
        }

        return $spellings;
    }

    /**
     * @param class-string $class
     *
     * @return array<mixed>
     */
    private function read(string $class, string $property): array
    {
        $value = (new \ReflectionProperty($class, $property))->getValue();
        $this->assertIsArray($value);

        return $value;
    }

    /**
     * @param class-string $class
     * @param array<mixed> $value
     */
    private function write(string $class, string $property, array $value): void
    {
        (new \ReflectionProperty($class, $property))->setValue(null, $value);
    }
}
