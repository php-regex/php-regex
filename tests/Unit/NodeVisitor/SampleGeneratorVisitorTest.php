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

namespace RegexParser\Tests\Unit\NodeVisitor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RegexParser\Exception\LexerException;
use RegexParser\NodeVisitor\SampleGeneratorNodeVisitor;
use RegexParser\Regex;

final class SampleGeneratorVisitorTest extends TestCase
{
    private Regex $regex;

    private SampleGeneratorNodeVisitor $generator;

    protected function setUp(): void
    {
        $this->regex = Regex::create();
        $this->generator = new SampleGeneratorNodeVisitor();
        $this->generator->setSeed(42); // Deterministic
    }

    public function test_generate_simple(): void
    {
        $this->assertSampleMatches('/abc/');
    }

    public function test_generate_alternation(): void
    {
        $this->assertSampleMatches('/a|b|c/');
    }

    public function test_generate_quantifiers(): void
    {
        $this->assertSampleMatches('/a{2,5}/');
        $this->assertSampleMatches('/b*/'); // Can generate empty string
        $this->assertSampleMatches('/c+/');
    }

    public function test_generate_char_classes(): void
    {
        $this->assertSampleMatches('/[a-z]/');
        $this->assertSampleMatches('/[0-9]{3}/');
        $this->assertSampleMatches('/[a-zA-Z0-9]/');
    }

    public function test_generate_special_types(): void
    {
        $this->assertSampleMatches('/\d\s\w/');
    }

    public function test_generate_groups_and_backrefs(): void
    {
        // Complex test: backreference. The generator must remember what it generated.
        // (a|b)\1 must generate "aa" or "bb", but never "ab"
        $regex = Regex::create();
        $ast = $regex->parse('/(a|b)\1/');
        $generator = new SampleGeneratorNodeVisitor();

        $sample = $ast->accept($generator);
        $this->assertContains($sample, ['aa', 'bb']);
    }

    public function test_seeding(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/[a-z]{10}/');
        $generator = new SampleGeneratorNodeVisitor();

        $generator->setSeed(12345);
        $sample1 = $ast->accept($generator);

        $generator->setSeed(12345);
        $sample2 = $ast->accept($generator);

        $this->assertSame($sample1, $sample2, 'Seeding should produce deterministic results');
    }

    public function test_generate_all_char_types(): void
    {
        // Test char types that are typically complex to mock
        $regex = '/\D\S\W\h\H\v\V\R/';
        $this->assertSampleMatches($regex);
    }

    public function test_generate_unicode_and_octal_escapes(): void
    {
        // \xNN, \u{NNNN}, \o{NNN}, \0NN
        // Note: PHP PCRE doesn't support \u{} and \o{} syntax, so we test the generated output directly
        $regex = '/\x41\xE9\o{40}\010/';
        $ast = $this->regex->parse($regex);
        $generator = new SampleGeneratorNodeVisitor();
        $sample = $ast->accept($generator);

        // Expected: \x41 = 'A', \xE9 = the byte 0xE9 (no UTF mode), \o{40} = ' ' (space, octal 40 = decimal 32), \010 = backspace (octal 10 = decimal 8)
        $expected = "A\xE9 \x08";
        $this->assertSame($expected, $sample);
        $this->assertSame(1, preg_match($regex, $sample));
    }

    public function test_generate_complex_backrefs(): void
    {
        // Named backref (\k<name>)
        $this->assertSampleMatches('/(?<n1>\d{1,2})foo\k<n1>/'); // \k<name>

        // Note: Optional groups with backrefs are tricky because if the group doesn't match,
        // the backref fails the entire match in PCRE. The generator randomly chooses 0 or 1
        // for '?', so we test this separately to ensure it can generate a valid match.
        $ast = $this->regex->parse('/(?<name>a)?\k<name>/');
        $generator = new SampleGeneratorNodeVisitor();

        // Try multiple times - at least one should generate 'aa' (when group matches)
        $validSampleFound = false;
        for ($i = 0; $i < 10; $i++) {
            $sample = $ast->accept($generator);
            if ('aa' === $sample) {
                $validSampleFound = true;

                break;
            }
        }
        $this->assertTrue($validSampleFound, 'Should be able to generate valid sample "aa" for optional group with backref');
    }

    public function test_generate_conditional_always_chooses_a_branch(): void
    {
        // Conditional with lookahead condition randomly chooses yes/no branch.
        // Note: The pattern is parsed as: condition=(?=\d), yes=(Y|N), no=''
        // So the generator can produce 'Y', 'N', or '' (when no branch is chosen)
        $regex = Regex::create();
        $ast = $regex->parse('/(?(?=\d)Y|N)/');
        $generator = new SampleGeneratorNodeVisitor();

        $output = $ast->accept($generator);
        // The output should be one of these values based on how the parser interprets the pattern
        $this->assertContains($output, ['Y', 'N', ''], "Expected 'Y', 'N', or '', got: ".var_export($output, true));
    }

    public function test_generate_negated_char_class_safe_char(): void
    {
        // The sample must actually match the negated class.
        $sample = $this->generateSample('/[^a]/');
        $this->assertMatchesRegularExpression('/^[^a]$/', $sample);
    }

    public function test_generate_throws_on_subroutine(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Sample generation for subroutines is not supported.');
        $this->generateSample('/(?R)/');
    }

    public function test_generate_throws_on_empty_char_class(): void
    {
        // Empty character class /[]/ is actually a lexer error (unclosed class with ] as literal)
        $this->expectException(LexerException::class);
        $this->expectExceptionMessage('Unclosed character class');
        $this->generateSample('/[]/');
    }

    /**
     * @return \Iterator<array{string}>
     */
    public static function providePosixClasses(): \Iterator
    {
        // We must provide delimiters (/) so the parser doesn't interpret [] as delimiters
        yield ['/[[:alnum:]]/'];
        yield ['/[[:alpha:]]/'];
        yield ['/[[:digit:]]/'];
        yield ['/[[:xdigit:]]/'];
        yield ['/[[:space:]]/'];
        yield ['/[[:lower:]]/'];
        yield ['/[[:upper:]]/'];
        yield ['/[[:punct:]]/'];
    }

    #[DataProvider('providePosixClasses')]
    public function test_generate_posix_classes(string $regex): void
    {
        $sample = $this->regex->parse($regex)->accept($this->generator);
        $this->assertNotEmpty($sample);
        // We verify it matches the regex itself to ensure correctness
        $this->assertMatchesRegularExpression($regex, $sample);
    }

    public function test_generate_all_whitespace_types(): void
    {
        // \h \H \v \V
        $regex = '/\h\H\v\V/';
        $sample = $this->regex->parse($regex)->accept($this->generator);
        // Basic sanity check length
        $this->assertSame(4, \strlen($sample));
    }

    public function test_reset_seed(): void
    {
        $regex = '/[a-z]/';
        $ast = $this->regex->parse($regex);

        $this->generator->setSeed(123);
        $val1 = $ast->accept($this->generator);

        $this->generator->resetSeed();
        // Statistically, it *could* be the same, but unlikely with enough runs.
        // Mostly just testing the method runs without error.
        $val2 = $ast->accept($this->generator);

        $this->assertIsString($val1);
        $this->assertIsString($val2);
    }

    public function test_generate_negated_char_class_fallback(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/[^abc]/');
        $generator = new SampleGeneratorNodeVisitor();

        $result = $ast->accept($generator);
        $this->assertMatchesRegularExpression('/^[^abc]$/', $result);
    }

    public function test_generate_negated_char_class_excluding_default_placeholder(): void
    {
        // Regression: the generator used to return the constant '!' for any
        // negated class, which cannot match when '!' is in the excluded set.
        $regex = Regex::create();
        $sample = $regex->generate('/[^!"#]/');
        $this->assertMatchesRegularExpression('/^[^!"#]$/', $sample);
    }

    public function test_generate_fallback_char_types(): void
    {
        // Test less common types to ensure we go through all 'case' statements in the switch
        $types = ['\h', '\H', '\v', '\V', '\R'];
        foreach ($types as $t) {
            $regex = Regex::create();
            $this->assertNotEmpty($regex->generate('/'.$t.'/'));
        }
    }

    private function assertSampleMatches(string $regex): void
    {
        $ast = $this->regex->parse($regex);
        $generator = new SampleGeneratorNodeVisitor();

        for ($i = 0; $i < 5; $i++) {
            $sample = $ast->accept($generator);
            $this->assertMatchesRegularExpression($regex, $sample);
        }
    }

    private function generateSample(string $regex): string
    {
        $ast = $this->regex->parse($regex);
        $generator = new SampleGeneratorNodeVisitor();

        return $ast->accept($generator);
    }
}
