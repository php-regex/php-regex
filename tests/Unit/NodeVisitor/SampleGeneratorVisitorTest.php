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
use RegexParser\Node\CharLiteralNode;
use RegexParser\Node\CharLiteralType;
use RegexParser\Node\RangeNode;
use RegexParser\Node\RegexNode;
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

    public function test_generate_ranges_of_multibyte_characters(): void
    {
        // A range between two UTF-8 characters gives one of them, not a byte.
        $this->assertSampleMatches('/^[Ā-Ą]$/u');
        $this->assertSampleMatches('/^[Ä-Ü]{3}$/u');
        $this->assertSampleMatches('/^[\\x{100}-\\x{104}]$/u');
        $this->assertSampleMatches('/^[\\x41-\\x45]$/');
        $this->assertSampleMatches('/^[\\xe0-\\xef]$/');
    }

    #[DataProvider('provideEngineChecked')]
    public function test_generate_gives_a_sample_the_engine_matches(string $pattern): void
    {
        $this->assertSame(1, preg_match($pattern, Regex::create(['cache' => null])->generate($pattern)), $pattern);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideEngineChecked(): iterable
    {
        // A condition on a lookaround may take either branch.
        yield 'lookahead condition' => ['/^(?(?=abc)\\w{3}:|\\d\\d)$/'];
        yield 'negative lookahead condition' => ['/^(?(?!abc)\\d\\d|\\w{3}:)$/'];
        yield 'lookbehind condition' => ['/(?(?<=foo)bar|cat)/'];
        yield 'negative lookbehind condition' => ['/(?(?<!foo)cat|bar)/'];
        // POSIX classes, negated ones included, are asked of the engine.
        yield 'negated ascii under UTF' => ['/^[[:^ascii:]]$/u'];
        yield 'no printable character under UTF' => ['/^[[:^print:]]+$/u'];
        yield 'word character past ASCII' => ['/^[^[:ascii:]\\W]$/u'];
        yield 'ascii' => ['/^[[:ascii:]]$/'];
        yield 'negated digit' => ['/^[[:^digit:]]$/'];
        // Groups are numbered as PCRE numbers them, branch resets included.
        yield 'call past a branch reset' => ['/^X(?5)(a)(?|(b)|(q))(c)(d)(Y)$/'];
        yield 'call into a nested branch reset' => ['/^X(?7)(a)(?|(b|(r)(s))|(q))(c)(d)(Y)$/'];
        yield 'reference past a branch reset' => ['/^(?|(a)|(b))(c)\\2$/'];
        // A name several groups share refers to the first of them that captured.
        yield 'duplicate names' => ['/^(?<n>A)(?:(?<n>foo)|(?<n>bar))\\k<n>$/J'];
        yield 'duplicate names, Python spelling' => ['/^(?P<same>a)(?P<same>b)(?P=same)$/J'];
        yield 'names in a branch reset' => ['/^(?|(?\'a\'aaa)|(?\'a\'b))\\k\'a\'$/'];
        // A lookahead's captures hold for what follows it.
        yield 'reference to a capture in a lookahead' => ['/^(?=(\\w+))\\1:/'];
        yield 'named capture in a lookahead' => ['/(?=(?\'abc\'\\w+))\\k<abc>:/'];
        yield 'optional reference to a lookahead capture' => ['/(?=(a))\\1?b/'];
        // Names past ASCII.
        yield 'reference by a UTF-8 name' => ['/(?\'ABáC\'...)\\g{ABáC}/u'];
        yield 'call and reference by a UTF-8 name' => ['/^(?\'אABC\'...)(?&אABC)(?P=אABC)/u'];
        // A lookahead in a plain group holds the text after the group.
        yield 'word start and end' => ['/[[:<:]]red[[:>:]]/'];
        yield 'lookahead closing a group' => ['/^(?:a(?=bc))bcd$/'];
    }

    public function test_a_range_across_the_surrogates_gives_no_surrogate(): void
    {
        $ast = $this->regex->parse('/[\\x{D7FF}-\\x{E000}]/u');
        $generator = new SampleGeneratorNodeVisitor();
        $generator->setSeed(1);

        for ($try = 0; $try < 8; $try++) {
            $sample = $ast->accept($generator);
            $this->assertSame(1, preg_match('/^[\\x{D7FF}-\\x{E000}]$/u', $sample), bin2hex($sample));
        }
    }

    public function test_the_first_and_last_surrogates_are_avoided(): void
    {
        // Seed 748 draws U+D800 from the range, seed 1635 U+DFFF: both give
        // the first code point instead.
        $ast = $this->regex->parse('/[\\x{D7FF}-\\x{E000}]/u');
        foreach ([748, 1635] as $seed) {
            $generator = new SampleGeneratorNodeVisitor();
            $generator->setSeed($seed);
            $this->assertSame("\u{D7FF}", $ast->accept($generator), (string) $seed);
        }
    }

    public function test_a_range_between_characters_utf8_cannot_hold_gives_nothing(): void
    {
        // Built by hand: PCRE refuses such ends.
        $surrogates = new RangeNode(new CharLiteralNode('\\x{D800}', 0xD800, CharLiteralType::UNICODE, 1, 9), new CharLiteralNode('\\x{D800}', 0xD800, CharLiteralType::UNICODE, 10, 18), 1, 18);
        $this->assertSame('', (new RegexNode($surrogates, 'u', '/', 0, 19))->accept(new SampleGeneratorNodeVisitor()));

        $beyond = new RangeNode(new CharLiteralNode('\\x{110000}', 0x110000, CharLiteralType::UNICODE, 1, 11), new CharLiteralNode('\\x{110001}', 0x110001, CharLiteralType::UNICODE, 12, 22), 1, 22);
        $this->assertSame('?', (new RegexNode($beyond, 'u', '/', 0, 23))->accept(new SampleGeneratorNodeVisitor()));
    }

    public function test_a_lookahead_the_text_misses_is_laid_over_it(): void
    {
        $ast = $this->regex->parse('/^(?=ab)\\d\\d/');
        $generator = new SampleGeneratorNodeVisitor();

        $this->assertStringStartsWith('ab', $ast->accept($generator));
    }

    #[DataProvider('provideRangesAtTheEdges')]
    public function test_a_range_gives_more_than_its_first_character(string $pattern): void
    {
        $ast = $this->regex->parse($pattern);
        $generator = new SampleGeneratorNodeVisitor();

        $samples = [];
        for ($try = 0; $try < 24; $try++) {
            $generator->setSeed($try);
            $sample = $ast->accept($generator);
            $this->assertSame(1, preg_match('/^'.substr($pattern, 1, strrpos($pattern, '/') - 1).'$/'.substr($pattern, strrpos($pattern, '/') + 1), $sample), bin2hex($sample));
            $samples[$sample] = true;
        }

        $this->assertGreaterThan(1, \count($samples), $pattern);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRangesAtTheEdges(): iterable
    {
        yield 'from the first code point' => ['/[\\x00-\\x02]/'];
        yield 'up to the last code point' => ['/[\\x{10FFFD}-\\x{10FFFF}]/u'];
        yield 'escaped ends under UTF' => ['/[\\x{100}-\\x{104}]/u'];
        yield 'odd bytes' => ['/[\\x41-\\x43]/'];
    }

    #[DataProvider('provideLaidOut')]
    public function test_a_lookaround_is_laid_over_the_text_only_where_it_misses(string $pattern, string $sample): void
    {
        $this->assertSame($sample, $this->regex->parse($pattern)->accept(new SampleGeneratorNodeVisitor()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLaidOut(): iterable
    {
        // Text the lookaround already holds is kept, text before it too.
        yield 'lookahead holding after text' => ['/x(?=ab)ab/', 'xab'];
        yield 'lookbehind holding, then more' => ['/red(?<=d)x/', 'redx'];
        yield 'multibyte lookahead under UTF' => ['/(?=.$)é/u', 'é'];
        // Where it misses, its text replaces the text at that place: the
        // lookahead the start of what follows, the lookbehind the end of
        // what precedes.
        yield 'lookahead missing at the start' => ['/(?=b)ab/', 'bb'];
        yield 'lookbehind missing at the end' => ['/ab(?<=a)c/', 'aac'];
    }

    public function test_a_lookaround_with_branches_is_judged_whole(): void
    {
        $generator = new SampleGeneratorNodeVisitor();
        $ahead = $this->regex->parse('/(?=a|b)cb/');
        $behind = $this->regex->parse('/bc(?<=b|x)d/');

        for ($try = 0; $try < 8; $try++) {
            $generator->setSeed($try);
            $this->assertMatchesRegularExpression('/^[ab]b$/', $ahead->accept($generator));
            $this->assertMatchesRegularExpression('/^b[bx]d$/', $behind->accept($generator));
        }
    }

    public function test_groups_are_numbered_through_script_runs_and_shared_names(): void
    {
        $generator = new SampleGeneratorNodeVisitor();
        $shared = '/^(?:(?<n>a)|(?<n>b))\\k<n>$/J';
        $inRun = '/^(*sr:(a))(b)(?2)$/';
        // In a branch reset, a call to a shared number runs the first group.
        $reset = '/^(?|(a)|(b))(?1)$/';

        for ($seed = 0; $seed < 8; $seed++) {
            $generator->setSeed($seed);
            $this->assertSame(1, preg_match($shared, $this->regex->parse($shared)->accept($generator)), (string) $seed);
            $this->assertSame('abb', $this->regex->parse($inRun)->accept($generator));
            $this->assertSame(1, preg_match($reset, $this->regex->parse($reset)->accept($generator)), (string) $seed);
        }
    }

    public function test_a_name_that_captured_nothing_yet_gives_nothing(): void
    {
        $this->assertSame('a', $this->regex->parse('/\\k<n>(?<n>a)/')->accept(new SampleGeneratorNodeVisitor()));
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

        // Try multiple times - at least one should generate 'aa' (when group
        // matches). Each try has an even chance: 64 tries miss once in 2^64.
        $validSampleFound = false;
        for ($i = 0; $i < 64; $i++) {
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
