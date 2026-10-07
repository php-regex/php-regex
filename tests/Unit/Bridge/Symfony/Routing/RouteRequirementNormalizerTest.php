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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Routing;

use PHPRegex\Symfony\Routing\RouteRequirementNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteRequirementNormalizerTest extends TestCase
{
    private RouteRequirementNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new RouteRequirementNormalizer();
    }

    public function test_normalize_already_delimited_pattern(): void
    {
        $patterns = [
            '/^test$/',
            '#^test$#',
            '~^test$~',
            '%^test$%',
        ];

        foreach ($patterns as $pattern) {
            $result = $this->normalizer->normalize($pattern);
            $this->assertSame($pattern, $result, "Pattern {$pattern} should remain unchanged");
        }
    }

    public function test_normalize_pattern_starting_with_anchor(): void
    {
        $pattern = '^test$';
        $expected = '#^test$#';

        $result = $this->normalizer->normalize($pattern);

        $this->assertSame($expected, $result);
    }

    public function test_normalize_simple_pattern(): void
    {
        $pattern = 'test';
        $expected = '#^test$#';

        $result = $this->normalizer->normalize($pattern);

        $this->assertSame($expected, $result);
    }

    public function test_normalize_pattern_with_special_chars(): void
    {
        $pattern = 'test[0-9]+';
        $expected = '#^test[0-9]+$#';

        $result = $this->normalizer->normalize($pattern);

        $this->assertSame($expected, $result);
    }

    public function test_normalize_pattern_with_delimiter_in_body(): void
    {
        $pattern = 'test#with#hashes';
        $expected = '~^test#with#hashes$~';

        $result = $this->normalizer->normalize($pattern);

        $this->assertSame($expected, $result);
    }

    public function test_normalize_pattern_with_other_delimiters_in_body(): void
    {
        $patterns = [
            'test/with/slashes' => '#^test/with/slashes$#',
            'test~with~tildes' => '#^test~with~tildes$#',
            'test%with%percent' => '#^test%with%percent$#',
        ];

        foreach ($patterns as $input => $expected) {
            $result = $this->normalizer->normalize($input);
            $this->assertSame($expected, $result, "Pattern '{$input}' should be normalized to '{$expected}'");
        }
    }

    public function test_normalize_pattern_starting_with_anchor_but_not_ending(): void
    {
        $pattern = '^test';
        $expected = '#^test$#';

        $result = $this->normalizer->normalize($pattern);

        $this->assertSame($expected, $result);
    }

    public function test_normalize_pattern_ending_with_anchor_but_not_starting(): void
    {
        $pattern = 'test$';
        $expected = '#^test$#';

        $result = $this->normalizer->normalize($pattern);

        $this->assertSame($expected, $result);
    }

    public function test_normalize_empty_pattern(): void
    {
        $pattern = '';
        $expected = '#^$#';

        $result = $this->normalizer->normalize($pattern);

        $this->assertSame($expected, $result);
    }

    public function test_normalize_pattern_with_regex_special_chars(): void
    {
        $patterns = [
            '[a-z]+' => '#^[a-z]+$#',
            '\d{2,4}' => '#^\d{2,4}$#',
            '(foo|bar)' => '#^(foo|bar)$#',
            '.*' => '#^.*$#',
            '^already^anchored$' => '#^already^anchored$#',
        ];

        foreach ($patterns as $input => $expected) {
            $result = $this->normalizer->normalize($input);
            $this->assertSame($expected, $result, "Pattern '{$input}' should be normalized to '{$expected}'");
        }
    }

    public function test_normalize_pattern_with_unicode_chars(): void
    {
        $pattern = 'test[äöü]';
        $expected = '#^test[äöü]$#';

        $result = $this->normalizer->normalize($pattern);

        $this->assertSame($expected, $result);
    }

    public function test_normalize_preserves_delimiter_choice(): void
    {
        // The normalizer always uses # as delimiter, regardless of what's in the pattern
        $pattern = 'test/with/slashes';

        $result = $this->normalizer->normalize($pattern);

        $this->assertSame('#^test/with/slashes$#', $result);
        $this->assertStringStartsWith('#', $result);
        $this->assertStringEndsWith('#', $result);
    }

    public function test_normalize_handles_edge_cases(): void
    {
        $patterns = [
            'a' => '#^a$#',
            '123' => '#^123$#',
            'a-b_c.d' => '#^a-b_c.d$#',
            'test_' => '#^test_$#',
            '#' => '#', // Already has delimiter
        ];

        foreach ($patterns as $input => $expected) {
            $result = $this->normalizer->normalize((string) $input);
            $this->assertSame($expected, $result, "Pattern '{$input}' should be normalized to '{$expected}'");
        }
    }

    public function test_normalize_with_various_anchor_combinations(): void
    {
        $patterns = [
            'test' => '#^test$#',
            '^test' => '#^test$#',
            'test$' => '#^test$#',
            '^test$' => '#^test$#', // This one gets special treatment
            '^anchored^pattern$' => '#^anchored^pattern$#',
        ];

        foreach ($patterns as $input => $expected) {
            $result = $this->normalizer->normalize($input);
            $this->assertSame($expected, $result, "Pattern '{$input}' should be normalized to '{$expected}'");
        }
    }

    public function test_normalize_uses_hash_as_default_delimiter(): void
    {
        $pattern = 'simple';
        $result = $this->normalizer->normalize($pattern);

        $this->assertStringStartsWith('#', $result);
        $this->assertStringEndsWith('#', $result);
        $this->assertStringContainsString('^simple$', $result);
    }

    /**
     * Symfony's RouteCompiler puts a requirement in its own group, once its
     * leading ^ or \A and its trailing $ or \z are stripped: "en|fr|de"
     * compiles to (?P<x>en|fr|de), so every alternative is anchored.
     */
    #[Test]
    #[DataProvider('provideRequirementsWithAlternatives')]
    public function test_normalize_groups_a_top_level_alternation_as_the_route_compiler_does(string $requirement, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalize($requirement));
    }

    /**
     * @return iterable<string, array{requirement: string, expected: string}>
     */
    public static function provideRequirementsWithAlternatives(): iterable
    {
        yield 'bare alternation' => ['requirement' => 'en|fr|de', 'expected' => '#^(?:en|fr|de)$#'];
        yield 'anchored alternation' => ['requirement' => '^en|fr$', 'expected' => '#^(?:en|fr)$#'];
        // The compiler strips \z and ends with $ under D: the end stays strict.
        yield 'string anchors' => ['requirement' => '\\Aen|fr\\z', 'expected' => '#^(?:en|fr)\\z#'];
        // Under an inline x, a comment runs to the newline: its parentheses are text.
        yield 'parenthesis in an x comment' => ['requirement' => "(?x)a # (\n|b", 'expected' => "~^(?:(?x)a # (\n|b)$~"];
        yield 'closing parenthesis in an x comment' => ['requirement' => "(?x)a # )\n|b", 'expected' => "~^(?:(?x)a # )\n|b)$~"];
        yield 'x turned off before the hash' => ['requirement' => '(?x)(?-x)a#(|b', 'expected' => '~^(?x)(?-x)a#(|b$~'];
        yield 'x in a group of its own' => ['requirement' => "(?x:a # (\n)|b", 'expected' => "~^(?:(?x:a # (\n)|b)$~"];
        // (?^ resets x: the # after it is a literal.
        yield 'x reset by a caret' => ['requirement' => '(?x)(?^i)a#|b', 'expected' => '~^(?:(?x)(?^i)a#|b)$~'];
        yield 'option setting never closed' => ['requirement' => '(?i', 'expected' => '#^(?i$#'];
        yield 'delimiter in an alternative' => ['requirement' => 'a#b|c', 'expected' => '~^(?:a#b|c)$~'];
        yield 'empty alternative' => ['requirement' => 'a|', 'expected' => '#^(?:a|)$#'];
        // preg_match('#^(?:a|b\$)$#', 'b$') is 1: the escaped $ is a literal, kept.
        yield 'escaped dollar kept' => ['requirement' => 'a|b\\$', 'expected' => '#^(?:a|b\\$)$#'];
        yield 'bar after a negated class' => ['requirement' => '[^|]|y', 'expected' => '#^(?:[^|]|y)$#'];
        // A comment's parentheses are text: preg_match('~^(?:(?#(x)|b)$~', 'x') is 0.
        yield 'parenthesis in a comment' => ['requirement' => '(?#(x)|b', 'expected' => '~^(?:(?#(x)|b)$~'];
        // [: with a ] before any :] is no POSIX class: the class ends at that ].
        yield 'bracket before a POSIX close' => ['requirement' => '[[:a]|b:]', 'expected' => '#^(?:[[:a]|b:])$#'];
        // An alternation that is already grouped, or that is no alternation.
        yield 'grouped' => ['requirement' => '(foo|bar)', 'expected' => '#^(foo|bar)$#'];
        yield 'bar in a class' => ['requirement' => '[a|b]x', 'expected' => '#^[a|b]x$#'];
        yield 'bar first in a class' => ['requirement' => '[]|]x', 'expected' => '#^[]|]x$#'];
        yield 'bar after a POSIX class' => ['requirement' => '[[:alpha:]|]x', 'expected' => '#^[[:alpha:]|]x$#'];
        yield 'escaped bar' => ['requirement' => 'a\|b', 'expected' => '#^a\|b$#'];
        yield 'quoted bar' => ['requirement' => '\Qa|b\E', 'expected' => '#^\Qa|b\E$#'];
        yield 'quoted to the end' => ['requirement' => '\Qa|b', 'expected' => '#^\Qa|b$#'];
        yield 'escaped bracket in a class' => ['requirement' => '[\\]|]x', 'expected' => '#^[\\]|]x$#'];
        yield 'class never closed' => ['requirement' => '[a|b', 'expected' => '#^[a|b$#'];
        yield 'comment never closed' => ['requirement' => '(?#x|b', 'expected' => '~^(?#x|b$~'];
        yield 'POSIX class never closed' => ['requirement' => '[[:alpha|b', 'expected' => '#^[[:alpha|b$#'];
    }

    /**
     * The engine reads the normalized pattern as Symfony's compiled route
     * reads the requirement.
     */
    #[Test]
    #[DataProvider('provideRequirementSubjects')]
    public function test_normalize_matches_what_the_compiled_route_matches(string $requirement, string $subject, bool $matches): void
    {
        $this->assertSame($matches ? 1 : 0, preg_match($this->normalizer->normalize($requirement), $subject));
    }

    /**
     * @return iterable<string, array{requirement: string, subject: string, matches: bool}>
     */
    public static function provideRequirementSubjects(): iterable
    {
        yield 'an alternative alone' => ['requirement' => 'en|fr|de', 'subject' => 'fr', 'matches' => true];
        yield 'first alternative with a suffix' => ['requirement' => 'en|fr|de', 'subject' => 'enx', 'matches' => false];
        yield 'last alternative with a prefix' => ['requirement' => 'en|fr|de', 'subject' => 'xde', 'matches' => false];
        yield 'anchored, middle with a prefix' => ['requirement' => '^en|fr$', 'subject' => 'xfr', 'matches' => false];
        yield 'string end anchor before a newline' => ['requirement' => '\\Aa\\z', 'subject' => "a\n", 'matches' => false];
        yield 'string end anchor' => ['requirement' => '\\Aa\\z', 'subject' => 'a', 'matches' => true];
        yield 'alternative after an x comment' => ['requirement' => "(?x)a # (\n|b", 'subject' => 'ab', 'matches' => false];
    }

    /**
     * A # in the requirement picks another delimiter rather than an escape:
     * an escape would double \#, break (?#...) and turn a comment under an
     * inline (?x) into a literal. Symfony's {...} delimiters escape nothing.
     */
    #[Test]
    #[DataProvider('provideRequirementsHoldingTheDelimiter')]
    public function test_normalize_keeps_a_hash_as_written(string $requirement, string $expected, string $subject): void
    {
        $pattern = $this->normalizer->normalize($requirement);

        $this->assertSame($expected, $pattern);
        $this->assertSame(1, preg_match($pattern, $subject));
    }

    /**
     * @return iterable<string, array{requirement: string, expected: string, subject: string}>
     */
    public static function provideRequirementsHoldingTheDelimiter(): iterable
    {
        yield 'escaped hash' => ['requirement' => '^a\\#b$', 'expected' => '~^a\\#b$~', 'subject' => 'a#b'];
        yield 'comment' => ['requirement' => '^(?#c)a$', 'expected' => '~^(?#c)a$~', 'subject' => 'a'];
        yield 'comment under an inline x' => ['requirement' => '(?x)a #c', 'expected' => '~^(?x)a #c$~', 'subject' => 'a'];
        yield 'tilde taken too' => ['requirement' => 'a#~', 'expected' => '%^a#~$%', 'subject' => 'a#~'];
        // Every delimiter is taken: the unescaped # is escaped, the escaped one kept.
        yield 'six delimiters taken' => ['requirement' => '\\Q#\\E~%!@;', 'expected' => '+^\\Q#\\E~%!@;$+', 'subject' => '#~%!@;'];
        // Every delimiter is taken: the unescaped # is escaped, the escaped one kept.
        yield 'every delimiter taken' => ['requirement' => '\\##~%!@;\\+=,:&"\'`', 'expected' => '#^\\#\\#~%!@;\\+=,:&"\'`$#', 'subject' => '##~%!@;+=,:&"\'`'];
    }
}
