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

namespace PHPRegex\Tests\Integration\Sweep;

use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

/**
 * A sweep of patterns through SampleGenerator.
 *
 * These cases were written to reach branches rather than to describe a
 * behaviour, and they were spread over eight files named after the metric
 * they served. They are grouped by what they exercise instead.
 */
final class SampleGeneratorSweepTest extends TestCase
{
    private Regex $regex;

    private Regex $regexService;

    private SampleGenerator $sampleVisitor;

    protected function setUp(): void
    {
        $this->regex = Regex::create();
        $this->regexService = Regex::create();
        $this->sampleVisitor = new SampleGenerator();
    }

    public function test_sample_generator_with_seed(): void
    {
        $generator = new SampleGenerator();
        $generator->setSeed(12345);

        $regex = Regex::create();
        $ast = $regex->parse('/[a-z]/');
        $result = $ast->accept($generator);
        $this->assertNotEmpty($result);
    }

    public function test_sample_generator_reset_seed(): void
    {
        $generator = new SampleGenerator();
        $generator->setSeed(12345);
        $generator->resetSeed();

        $regex = Regex::create();
        $ast = $regex->parse('/[a-z]/');
        $result = $ast->accept($generator);
        $this->assertNotEmpty($result);
    }

    public function test_sample_generator_unicode_prop(): void
    {
        $generator = new SampleGenerator();
        $regex = Regex::create();

        // Test \p{L}
        $ast = $regex->parse('/\p{L}/');
        $result = $ast->accept($generator);
        $this->assertNotEmpty($result);
    }

    public function test_sample_generator_backref_named(): void
    {
        $generator = new SampleGenerator();
        $regex = Regex::create();

        $ast = $regex->parse('/(?<name>abc)\k<name>/');
        $result = $ast->accept($generator);
        $this->assertStringContainsString('abc', $result);
    }

    public function test_sample_generator_group_non_capturing(): void
    {
        $generator = new SampleGenerator();
        $regex = Regex::create();

        $ast = $regex->parse('/(?:abc)/');
        $result = $ast->accept($generator);
        $this->assertStringContainsString('abc', $result);
    }

    public function test_sample_generator_dot_node(): void
    {
        $ast = $this->regex->parse('/./');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertNotEmpty($sample);
    }

    public function test_sample_generator_keep_node(): void
    {
        $ast = $this->regex->parse('/\K/');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertIsString($sample);
    }

    public function test_sample_generator_anchor_nodes(): void
    {
        $ast = $this->regex->parse('/^$/');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertIsString($sample);
    }

    public function test_sample_generator_assertion_nodes(): void
    {
        $ast = $this->regex->parse('/\b\B/');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertIsString($sample);
    }

    public function test_sample_generator_comment_node(): void
    {
        $ast = $this->regex->parse('/(?#comment)/');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertIsString($sample);
    }

    public function test_sample_generator_pcre_verb_node(): void
    {
        $ast = $this->regex->parse('/(*FAIL)/');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertIsString($sample);
    }

    public function test_sample_generator_unicode_without_hex_pattern(): void
    {
        // Test unicode node that doesn't match hex patterns (fallback case)
        $ast = $this->regex->parse('/\x41/');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertNotEmpty($sample);
    }

    public function test_sample_generator_posix_class_unknown(): void
    {
        // Test posix class with unknown class to hit default case
        // Since we can't create an unknown posix class through parser,
        // just test various valid ones to ensure coverage
        $classes = ['ascii', 'graph', 'print'];
        foreach ($classes as $class) {
            try {
                $ast = $this->regex->parse("/[[:$class:]]/");
                $sample = $ast->accept($this->sampleVisitor);
                $this->assertIsString($sample);
            } catch (\Exception) {
                // Some classes may not be valid, that's ok
            }
        }
    }

    public function test_sample_generator_quantifier_ranges(): void
    {
        // Test parseQuantifierRange with different quantifier types
        $ast = $this->regex->parse('/a{5}/'); // exact
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertIsString($sample);

        $ast = $this->regex->parse('/a{2,5}/'); // range
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertIsString($sample);

        $ast = $this->regex->parse('/a{2,}/'); // open-ended
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertIsString($sample);
    }

    public function test_sample_generator_char_types(): void
    {
        // Test generateForCharType with different char types
        $ast = $this->regex->parse('/\d\D\s\S\w\W\h\H\v\R/');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertIsString($sample);
    }

    public function test_sample_generator_conditional_yes_and_no_paths(): void
    {
        // Test conditional to hit both yes and no paths (run multiple times due to randomness)
        $ast = $this->regex->parse('/(a)?(?(?=b)yes|no)/');
        for ($i = 0; $i < 20; $i++) {
            $sample = $ast->accept($this->sampleVisitor);
            $this->assertIsString($sample);
        }
    }

    public function test_sample_generator_unicode_prop_various(): void
    {
        // Test visitUnicodeProp with properties that don't contain L, N, or P
        $ast = $this->regex->parse('/\p{Z}\p{S}\p{M}\p{C}/');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertIsString($sample);
    }

    public function test_sample_generator_unicode_prop_without_l_n_p(): void
    {
        // Test unicode properties that don't contain L, N, or P to hit the fallback
        $ast = $this->regexService->parse('/\p{Z}/'); // Separator
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertNotEmpty($sample);

        $ast = $this->regexService->parse('/\p{S}/'); // Symbol
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertNotEmpty($sample);

        $ast = $this->regexService->parse('/\p{M}/'); // Mark
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertNotEmpty($sample);

        $ast = $this->regexService->parse('/\p{C}/'); // Other
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertNotEmpty($sample);
    }

    public function test_sample_generator_unicode_prop_with_l(): void
    {
        $ast = $this->regexService->parse('/\p{L}/'); // Letter
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertNotEmpty($sample);
        $this->assertMatchesRegularExpression('/^\p{L}$/', $sample);
    }

    public function test_sample_generator_unicode_prop_with_n(): void
    {
        $ast = $this->regexService->parse('/\p{N}/'); // Number
        $sample = $ast->accept($this->sampleVisitor);
        // "0" is a number, and empty() says it is empty.
        $this->assertNotSame('', $sample);
        $this->assertMatchesRegularExpression('/^\p{N}$/', $sample);
    }

    public function test_sample_generator_unicode_prop_with_p(): void
    {
        $ast = $this->regexService->parse('/\p{P}/'); // Punctuation
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertNotEmpty($sample);
        $this->assertMatchesRegularExpression('/^\p{P}$/', $sample);
    }

    public function test_sample_generator_conditional_no_path(): void
    {
        // Test conditional with NO path - need to generate multiple samples to hit both paths
        $ast = $this->regexService->parse('/(a)?(?(?=b)yes|no)/');

        for ($i = 0; $i < 10; $i++) {
            $sample = $ast->accept($this->sampleVisitor);
            $this->assertIsString($sample); // Can be empty string
        }
    }

    public function test_sample_generator_set_seed(): void
    {
        $this->sampleVisitor->setSeed(12345);
        $ast = $this->regexService->parse('/[a-z]+/');
        $sample1 = $ast->accept($this->sampleVisitor);

        $this->sampleVisitor->setSeed(12345);
        $sample2 = $ast->accept($this->sampleVisitor);

        // Same seed should produce same result
        $this->assertSame($sample1, $sample2);
    }

    public function test_sample_generator_reset_seed_2(): void
    {
        $this->sampleVisitor->setSeed(12345);
        $ast = $this->regexService->parse('/[a-z]+/');
        $sample1 = $ast->accept($this->sampleVisitor);

        $this->sampleVisitor->resetSeed();
        $sample2 = $ast->accept($this->sampleVisitor);

        // After reset, results may differ
        $this->assertIsString($sample2);
    }

    public function test_sample_generator_empty_alternation(): void
    {
        // Edge case: alternation with empty alternatives
        $ast = $this->regexService->parse('/(|a)/');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertIsString($sample);
    }

    public function test_sample_generator_backref_not_set(): void
    {
        // Backref to group that hasn't captured yet
        $ast = $this->regexService->parse('/\1(a)/');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertIsString($sample);
    }

    public function test_sample_generator_named_backref(): void
    {
        $ast = $this->regexService->parse('/(?P<name>a)\k<name>/');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertNotEmpty($sample);
    }

    public function test_sample_generator_group_with_name(): void
    {
        $ast = $this->regexService->parse('/(?<letter>[a-z])(?<digit>\d)/');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertNotEmpty($sample);
    }

    public function test_sample_generator_unicode_hex(): void
    {
        $ast = $this->regexService->parse('/\x41\x42/');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertNotEmpty($sample);
    }

    public function test_sample_generator_unicode_braces(): void
    {
        $ast = $this->regexService->parse('/\u{41}\u{42}/');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertNotEmpty($sample);
    }

    public function test_sample_generator_octal_braces(): void
    {
        $ast = $this->regexService->parse('/\o{101}/');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertNotEmpty($sample);
    }

    public function test_sample_generator_octal_legacy_variations(): void
    {
        $ast = $this->regexService->parse('/\01\02\07/');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertNotEmpty($sample);
    }

    public function test_sample_generator_posix_classes_all(): void
    {
        $classes = [
            '[[:alpha:]]', '[[:alnum:]]', '[[:digit:]]', '[[:xdigit:]]',
            '[[:space:]]', '[[:lower:]]', '[[:upper:]]', '[[:punct:]]',
            '[[:word:]]', '[[:blank:]]', '[[:cntrl:]]', '[[:graph:]]',
            '[[:print:]]',
        ];

        foreach ($classes as $class) {
            $ast = $this->regexService->parse('/'.$class.'/');
            $sample = $ast->accept($this->sampleVisitor);
            $this->assertIsString($sample);
        }
    }

    public function test_sample_generator_quantifier_exact(): void
    {
        $ast = $this->regexService->parse('/a{5}/');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertSame(5, \strlen($sample));
    }

    public function test_sample_generator_quantifier_range(): void
    {
        $ast = $this->regexService->parse('/a{2,4}/');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertGreaterThanOrEqual(2, \strlen($sample));
        $this->assertLessThanOrEqual(4, \strlen($sample));
    }

    public function test_sample_generator_quantifier_open_range(): void
    {
        $ast = $this->regexService->parse('/a{2,}/');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertGreaterThanOrEqual(2, \strlen($sample));
    }

    public function test_sample_generator_non_capturing_group(): void
    {
        $ast = $this->regexService->parse('/(?:abc)+/');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertNotEmpty($sample);
    }

    public function test_sample_generator_atomic_group(): void
    {
        $ast = $this->regexService->parse('/(?>abc)/');
        $sample = $ast->accept($this->sampleVisitor);
        $this->assertNotEmpty($sample);
    }

    public function test_sample_generator_edge_cases(): void
    {
        // Alternation
        $sample = $this->regexService->generate('/a|b|c/');
        $this->assertMatchesRegularExpression('/^[abc]$/', $sample);

        // Optional group
        $sample = $this->regexService->generate('/a(bc)?d/');
        $this->assertMatchesRegularExpression('/^a(bc)?d$/', $sample);

        // Nested quantifiers
        $sample = $this->regexService->generate('/(a+)+/');
        $this->assertNotEmpty($sample);
    }

    public function test_sample_generator_with_conditional(): void
    {
        // Test SampleGeneratorVisitor with conditional
        $ast = $this->regexService->parse('/(x)(?(1)y|z)/');

        $visitor = new SampleGenerator();
        $sample = $ast->accept($visitor);

        $this->assertIsString($sample);
    }

    public function test_sample_generator_with_pcre_verb(): void
    {
        // Test SampleGeneratorVisitor with PCRE verb
        $ast = $this->regexService->parse('/(*ACCEPT)test/');

        $visitor = new SampleGenerator();
        $sample = $ast->accept($visitor);

        $this->assertIsString($sample);
    }

    public function test_sample_generator_with_keep(): void
    {
        // Test SampleGeneratorVisitor with \K
        $ast = $this->regexService->parse('/prefix\Ksuffix/');

        $visitor = new SampleGenerator();
        $sample = $ast->accept($visitor);

        $this->assertIsString($sample);
    }

    public function test_sample_generator_with_octal_legacy(): void
    {
        // Test SampleGeneratorVisitor with octal legacy
        $ast = $this->regexService->parse('/\07/');

        $visitor = new SampleGenerator();
        $sample = $ast->accept($visitor);

        $this->assertIsString($sample);
    }

    public function test_sample_generator_with_unicode(): void
    {
        // Test SampleGeneratorVisitor with unicode sequences
        $ast = $this->regexService->parse('/\u{41}/');

        $visitor = new SampleGenerator();
        $sample = $ast->accept($visitor);

        $this->assertIsString($sample);
    }

    /**
     * Tests the "empty" fallback of getRandomChar in SampleGeneratorVisitor.
     * This case is impossible via the public API as the visitor never passes an empty array.
     */
    public function test_sample_generator_get_random_char_empty(): void
    {
        $visitor = new SampleGenerator();
        $reflection = new \ReflectionClass($visitor);
        $method = $reflection->getMethod('getRandomChar');

        // Direct call: getRandomChar([])
        $result = $method->invoke($visitor, []);

        $this->assertSame('?', $result);
    }
}
