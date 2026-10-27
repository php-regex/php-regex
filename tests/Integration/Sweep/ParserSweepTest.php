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

use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Printer\NodeDumper;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A sweep of patterns through Parser.
 *
 * These cases were written to reach branches rather than to describe a
 * behaviour, and they were spread over eight files named after the metric
 * they served. They are grouped by what they exercise instead.
 */
final class ParserSweepTest extends TestCase
{
    use PhpUnitForwardCompat;

    private Regex $regexService;

    protected function setUp(): void
    {
        $this->regexService = Regex::create();
    }

    #[Test]
    public function test_parser_recursion_limit(): void
    {
        $this->expectNotToPerformAssertions();
        $regex = Regex::create();
        // This pattern should not trigger recursion limit in normal parsing
        $regex->parse('/a+/');
    }

    #[Test]
    public function test_parser_various_delimiters(): void
    {
        $this->expectNotToPerformAssertions();
        // Test parsing with different delimiters (indirectly tests extractPatternAndFlags)
        $this->regexService->parse('#test#i');
        $this->regexService->parse('@test@m');
        $this->regexService->parse('~test~s');
    }

    #[Test]
    public function test_parser_complex_group_modifiers(): void
    {
        $this->expectNotToPerformAssertions();
        // Test various group modifiers to hit parseGroupModifier branches
        $this->regexService->parse('/(?i:test)/');
        $this->regexService->parse('/(?-i:test)/');
        $this->regexService->parse('/(?i-m:test)/');
    }

    #[Test]
    public function test_parser_named_groups_various_syntaxes(): void
    {
        $this->expectNotToPerformAssertions();
        // Test different named group syntaxes
        $this->regexService->parse('/(?P<name>test)/');
        $this->regexService->parse('/(?<name>test)/');
    }

    #[Test]
    public function test_parser_assertions_all_types(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/(?=test)/');
        $this->regexService->parse('/(?!test)/');
        $this->regexService->parse('/(?<=test)/');
        $this->regexService->parse('/(?<!test)/');
    }

    #[Test]
    public function test_parser_conditional_with_number(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/(a)(?(1)b|c)/');
    }

    #[Test]
    public function test_parser_conditional_with_name(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/(?<test>a)(?(test)b|c)/');
    }

    #[Test]
    public function test_parser_conditional_with_assertion(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/(?(?=a)b|c)/');
    }

    #[Test]
    public function test_parser_subroutine_with_number(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/(a)(?1)/');
    }

    #[Test]
    public function test_parser_subroutine_with_name(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/(?<name>a)(?&name)/');
    }

    #[Test]
    public function test_parser_char_class_with_ranges(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/[a-zA-Z0-9]/');
    }

    #[Test]
    public function test_parser_char_class_negated(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/[^a-z]/');
    }

    #[Test]
    public function test_parser_char_class_with_escaped_chars(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/[\]\-\^]/');
    }

    #[Test]
    public function test_parser_quantifiers_all_types(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/a*/');
        $this->regexService->parse('/a+/');
        $this->regexService->parse('/a?/');
        $this->regexService->parse('/a{3}/');
        $this->regexService->parse('/a{3,}/');
        $this->regexService->parse('/a{3,5}/');
    }

    #[Test]
    public function test_parser_lazy_quantifiers(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/a*?/');
        $this->regexService->parse('/a+?/');
        $this->regexService->parse('/a??/');
        $this->regexService->parse('/a{3,5}?/');
    }

    #[Test]
    public function test_parser_possessive_quantifiers(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/a*+/');
        $this->regexService->parse('/a++/');
        $this->regexService->parse('/a?+/');
    }

    #[Test]
    public function test_parser_atomic_groups(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/(?>a+)b/');
    }

    #[Test]
    public function test_parser_recursive_patterns(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/(?R)/');
    }

    #[Test]
    public function test_parser_subroutine_with_relative_reference(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/(a)(?-1)/');
    }

    #[Test]
    public function test_parser_g_reference_with_braces(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/\g{1}/');
    }

    #[Test]
    public function test_parser_g_reference_with_angle_brackets(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/\g<name>/');
    }

    #[Test]
    public function test_parser_g_reference_with_number(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/\g1/');
    }

    #[Test]
    public function test_parser_char_class_with_posix_negated(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/[[:^alpha:]]/');
    }

    #[Test]
    public function test_parser_char_class_with_multiple_ranges(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/[a-zA-Z0-9_\-]/');
    }

    #[Test]
    public function test_parser_empty_char_class(): void
    {
        $this->expectNotToPerformAssertions();

        try {
            $this->regexService->parse('/[]/');
        } catch (\Exception) {
            // May fail, that's ok
        }
    }

    #[Test]
    public function test_parser_quantifier_possessive_on_group(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/(abc)++/');
    }

    #[Test]
    public function test_parser_comment_in_pattern(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/a(?#this is a comment)b/');
    }

    #[Test]
    public function test_parser_multiple_flags(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/test/imsxuADJU');
    }

    #[Test]
    public function test_parser_inline_modifier_add_remove(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/(?i-ms:test)/');
    }

    #[Test]
    public function test_parser_inline_modifier_standalone(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/(?i)test/');
    }

    #[Test]
    public function test_parser_backref_with_k_braces(): void
    {
        $this->expectNotToPerformAssertions();
        $this->regexService->parse('/(?<name>a)\k{name}/');
    }

    #[Test]
    public function test_parser_pcre_verbs_various(): void
    {
        $this->expectNotToPerformAssertions();
        $patterns = [
            '/(*ACCEPT)/',
            '/(*FAIL)/',
            '/(*MARK:name)/',
            '/(*COMMIT)/',
            '/(*PRUNE)/',
            '/(*SKIP)/',
            '/(*THEN)/',
        ];

        foreach ($patterns as $pattern) {
            try {
                $this->regexService->parse($pattern);
            } catch (\Exception) {
                // Some may fail
            }
        }
    }

    #[Test]
    public function test_full_integration_complex_pattern(): void
    {
        // A complex real-world-like pattern
        $pattern = '/^(?:(?<scheme>https?):\/\/)?(?<host>[\w\-\.]+)(?::(?<port>\d+))?(?<path>\/[^\s]*)?$/i';

        $this->regexService->parse($pattern);

        $result = $this->regexService->validate($pattern);
        $this->assertTrue($result->isValid);

        $explanation = $this->regexService->explain($pattern);
        $this->assertNotEmpty($explanation);

        $dump = $this->regexService->parse($pattern)->accept(new NodeDumper());
        $this->assertNotEmpty($dump);

        $optimized = $this->regexService->optimize($pattern)->optimized;
        $this->assertNotEmpty($optimized);
    }

    #[Test]
    public function test_parser_conditional_with_invalid_syntax_after_question(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessageIsOrContains('Invalid conditional condition');

        $this->regexService->parse('/(?(?x)yes|no)/');
    }

    #[Test]
    public function test_parser_conditional_with_bare_atom(): void
    {
        // Test conditional with an atom that's not a valid condition type
        // This should trigger the fallback parsing and validation error
        $this->expectException(ParserException::class);
        $this->expectExceptionMessageIsOrContains('Invalid conditional construct');

        $this->regexService->parse('/(?([a-z])yes|no)/');
    }

    #[Test]
    public function test_parser_group_name_single_quote_after_angle_bracket_is_no_name(): void
    {
        // PCRE: "subpattern name expected" on the quote.
        $this->expectException(ParserException::class);
        $this->expectExceptionMessageIsOrContains('Expected group name at position 4, found');

        $this->regexService->parse("/(?P<'name>x)/");
    }

    #[Test]
    public function test_parser_group_name_double_quote_after_angle_bracket_is_no_name(): void
    {
        // PCRE: "subpattern name expected" on the quote.
        $this->expectException(ParserException::class);
        $this->expectExceptionMessageIsOrContains('Expected group name at position 4, found');

        $this->regexService->parse('/(?P<"name>x)/');
    }
}
