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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan;

use PHPRegex\PHPStan\RegexPatternRule;
use PHPStan\Analyser\Error;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A constant replacement of preg_replace() or preg_filter() that refers to
 * a group the pattern does not have: PHP substitutes the empty string, or
 * for "${name}" nothing at all. Read after PHP's string escapes ("\1" in
 * double quotes is chr(1), no reference), with "\\" and "\$" escaping the
 * character after them; every member of a constant union; array patterns
 * paired with array replacements by index, a string replacement applying to
 * every pattern. Always reported, whatever the configuration.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexParserRuleReplacementGroupTest extends RuleTestCase
{
    private const FIXTURE = __DIR__.'/Fixtures/ReplacementGroupFixture.php';

    private const IDENTIFIER = 'regex.replacement.undefinedGroup';

    /**
     * Oracle, PHP 8.4.26 / PCRE2 10.49.
     */
    #[Test]
    public function test_the_engine_substitutes_nothing_for_an_undefined_group(): void
    {
        $this->assertSame('[]', preg_replace('/(a)/', '[$2]', 'a'));
        // Two digits are read: "$10" is group 10, not group 1 and a "0".
        $this->assertSame('[]', preg_replace('/(a)/', '[$10]', 'a'));
        $this->assertSame('[a0]', preg_replace('/(a)/', '[${1}0]', 'a'));
        // A name is never substituted.
        $this->assertSame('[${name}]', preg_replace('/(?<name>a)/', '[${name}]', 'a'));
        $this->assertSame('[]', preg_replace('/(a)/', '[\2]', 'a'));
        $this->assertSame('[]', preg_replace('/(a)/', '[${2}]', 'a'));
        // "\\" is a backslash, and the "$2" after it a reference still.
        $this->assertSame('[\]', preg_replace('/(a)/', '[\\\\$2]', 'a'));
        // Each pattern takes the replacement of its index, or the one string.
        $this->assertSame('[a][]', preg_replace(['/(a)/', '/b/'], ['[$1]', '[$1]'], 'ab'));
        $this->assertSame('[a][]', preg_replace(['/(a)/', '/b/'], '[$1]', 'ab'));
        // A branch reset numbers one group.
        $this->assertSame('[][]', preg_replace('/(?|(a)|(b))/', '[$2]', 'ab'));
        $this->assertSame('[]', preg_filter('/(a)/', '[$2]', 'a'));
    }

    /**
     * Oracle, PHP 8.4.26 / PCRE2 10.49.
     */
    #[Test]
    public function test_the_engine_reads_these_replacements_as_defined_or_literal(): void
    {
        $this->assertSame('[a a a a a a a0 a]', preg_replace('/(a)/', '[$0 \0 ${0} $1 ${1} \1 ${1}0 $01]', 'a'));
        // "\1" in double quotes is the byte 0x01, no reference.
        $this->assertSame("[\x01]", preg_replace('/(a)/', "[\1]", 'a'));
        // "\$" is a dollar sign, "\\" followed by "2" a backslash and a "2".
        $this->assertSame('[$2]', preg_replace('/(a)/', '[\$2]', 'a'));
        $this->assertSame('[\2]', preg_replace('/(a)/', '[\\\\2]', 'a'));
        $this->assertSame('[$x$]', preg_replace('/(a)/', '[$x$]', 'a'));
        // A missing replacement is the empty string.
        $this->assertSame('[a]', preg_replace(['/(a)/', '/b/'], ['[$1]'], 'ab'));
        $this->assertSame('[a][c]', preg_replace(['/(a)/', '/(b)(c)/'], ['[$1]', '[$2]'], 'abc'));
    }

    #[Test]
    public function test_a_reference_to_an_undefined_group_is_reported_on_its_line(): void
    {
        $this->assertSame([
            20 => '$2',
            21 => '$10',
            22 => '${name}',
            23 => '${name}',
            24 => '\2',
            25 => '\2',
            26 => '$2',
            27 => '${2}',
            28 => '$2',
            29 => '$1',
            30 => '$1',
            31 => '$2',
            32 => '$1',
            33 => '$2',
            53 => '$3',
        ], array_map(
            static fn (Error $error): string => self::referenceIn($error->getMessage()),
            $this->errorsByLine(),
        ));
    }

    #[Test]
    public function test_a_reference_glued_to_a_digit_suggests_the_braced_form(): void
    {
        $errors = $this->errorsByLine();

        $this->assertArrayHasKey(21, $errors);
        $this->assertStringContainsString('${1}0', (string) $errors[21]->getTip());
    }

    #[Test]
    public function test_a_named_reference_suggests_the_number_of_that_name(): void
    {
        $errors = $this->errorsByLine();

        $this->assertArrayHasKey(22, $errors);
        $this->assertStringContainsString('${1}', (string) $errors[22]->getTip());
    }

    #[Test]
    public function test_only_the_undefined_references_are_reported(): void
    {
        // Lines 38 to 48 refer to defined groups, or hold no reference.
        $this->assertSame([...range(20, 33), 53], array_keys($this->errorsByLine()));
    }

    /**
     * The message says how many groups the pattern has, in words that fit
     * the count.
     */
    #[Test]
    public function test_the_message_counts_the_groups_the_pattern_has(): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: group 3 of a two-group pattern is the empty string.
        $this->assertSame('[]', preg_replace('/(a)(b)/', '[$3]', 'ab'));

        $errors = $this->errorsByLine();

        $this->assertArrayHasKey(20, $errors);
        $this->assertSame('Replacement reference $2 names group 2, but /(a)/ has 1 capturing group: preg_replace() substitutes an empty string.', $errors[20]->getMessage());
        $this->assertArrayHasKey(53, $errors);
        $this->assertSame('Replacement reference $3 names group 3, but /(a)(b)/ has 2 capturing groups: preg_replace() substitutes an empty string.', $errors[53]->getMessage());
        $this->assertNull($errors[53]->getTip());
    }

    protected function getRule(): Rule
    {
        // No configuration: the check is always on.
        return new RegexPatternRule();
    }

    /**
     * @return array<int, Error> the errors of the identifier, by line; two on a line fail
     */
    private function errorsByLine(): array
    {
        $errors = [];
        foreach ($this->gatherAnalyserErrors([self::FIXTURE]) as $error) {
            if (self::IDENTIFIER !== $error->getIdentifier()) {
                continue;
            }

            $this->assertArrayNotHasKey((int) $error->getLine(), $errors, \sprintf('Line %d is reported twice.', (int) $error->getLine()));
            $errors[(int) $error->getLine()] = $error;
        }
        ksort($errors);

        return $errors;
    }

    /**
     * The reference a message quotes: the first "$n", "${…}" or "\n" in it.
     */
    private static function referenceIn(string $message): string
    {
        return 1 === preg_match('/\$\{[^}]*\}|\$\d+|\\\\\d+/', $message, $matches) ? $matches[0] : $message;
    }
}
