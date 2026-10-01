<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit;

use PhpRegex\Generator\SampleGenerator;
use PhpRegex\Parser\Analysis\ComplexityScorer;
use PhpRegex\Parser\Cache\NullCache;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Parser\RegexParser;
use PhpRegex\Parser\Validation\Validator;
use PhpRegex\Toolkit\Regex;
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
        $parser->parse('#a#')->accept(new PatternPrinter());

        foreach (self::caches() as [$class, $property]) {
            $this->assertNotSame([], $this->read($class, $property), 'Not warmed: '.$class.'::$'.$property);
        }

        $parser->clearCaches();

        foreach (self::caches() as [$class, $property]) {
            $this->assertSame([], $this->read($class, $property), 'Not cleared: '.$class.'::$'.$property);
        }
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
     * @return list<array{class-string, string}>
     */
    private static function caches(): array
    {
        return [
            [Validator::class, 'unicodePropCache'],
            [Validator::class, 'quantifierBoundsCache'],
            [ComplexityScorer::class, 'unboundedQuantifierCache'],
            [SampleGenerator::class, 'propertySamples'],
            [SampleGenerator::class, 'codePointChunks'],
            [PatternPrinter::class, 'delimiterCache'],
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
