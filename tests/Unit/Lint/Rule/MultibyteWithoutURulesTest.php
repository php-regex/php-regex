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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without /u PCRE reads the pattern as bytes: "[é]" is the class of the two
 * bytes of "é", and "é+" repeats its last byte. Both match strings nobody
 * meant; every verdict below is the engine's.
 */
final class MultibyteWithoutURulesTest extends TestCase
{
    private const IN_CLASS = 'regex.lint.unicode.multibyteInClassWithoutU';

    private const QUANTIFIED = 'regex.lint.unicode.quantifiedMultibyteWithoutU';

    #[Test]
    public function test_the_engine_reads_a_multibyte_class_as_bytes(): void
    {
        $this->assertSame(1, preg_match('/[é]/', 'à'));
        $this->assertSame(0, preg_match('/[é]/u', 'à'));
        $this->assertSame(1, preg_match('/^é+$/', "é\xA9"));
        $this->assertSame(0, preg_match('/^é+$/', 'éé'));
    }

    #[Test]
    #[DataProvider('provideByteClasses')]
    public function test_a_multibyte_character_in_a_class_without_u_is_an_error(string $pattern, string $character): void
    {
        $violation = $this->violation($pattern, self::IN_CLASS);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(LintSeverity::Error, $violation->severity);
        $this->assertStringContainsString('"'.$character.'"', $violation->message);
        $this->assertStringContainsString(strtoupper(bin2hex($character[0])), $violation->message);
    }

    #[Test]
    #[DataProvider('provideCleanClasses')]
    public function test_a_class_pcre_reads_as_meant_is_not_reported(string $pattern): void
    {
        $this->assertNull($this->violation($pattern, self::IN_CLASS));
    }

    #[Test]
    #[DataProvider('provideByteQuantifiers')]
    public function test_a_quantifier_on_a_multibyte_character_without_u_is_an_error(string $pattern): void
    {
        $violation = $this->violation($pattern, self::QUANTIFIED);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(LintSeverity::Error, $violation->severity);
        $this->assertStringContainsString('last byte', $violation->message);
    }

    #[Test]
    #[DataProvider('provideCleanQuantifiers')]
    public function test_a_quantifier_that_repeats_the_whole_character_is_not_reported(string $pattern): void
    {
        $this->assertNull($this->violation($pattern, self::QUANTIFIED));
    }

    #[Test]
    #[DataProvider('provideUtfVerbPatterns')]
    public function test_a_utf_verb_counts_as_the_u_flag(string $pattern, string $id): void
    {
        $this->assertSame(1, preg_match($pattern, 'é'), $pattern);
        $this->assertNull($this->violation($pattern, $id));
    }

    #[Test]
    public function test_the_class_rule_reports_each_class_once(): void
    {
        $ids = array_map(static fn (RuleViolation $violation): string => $violation->id, $this->lint('/[éè]/'));

        $this->assertCount(1, array_keys($ids, self::IN_CLASS, true));
    }

    /**
     * @return iterable<string, array{pattern: string, character: string}>
     */
    public static function provideByteClasses(): iterable
    {
        yield 'two bytes' => ['pattern' => '/[é]/', 'character' => 'é'];
        yield 'three bytes' => ['pattern' => '/[€]/', 'character' => '€'];
        yield 'four bytes' => ['pattern' => '/[😀]/', 'character' => '😀'];
        yield 'negated' => ['pattern' => '/[^é]/', 'character' => 'é'];
        yield 'range ends' => ['pattern' => '/[é-ü]/', 'character' => 'é'];
        yield 'next to ascii' => ['pattern' => '/[a-zé]/', 'character' => 'é'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideCleanClasses(): iterable
    {
        yield 'u flag' => ['pattern' => '/[é]/u'];
        yield '(*UTF)' => ['pattern' => '/(*UTF)[é]/'];
        yield 'ascii' => ['pattern' => '/[a-z]/'];
        yield 'byte escapes' => ['pattern' => '/[\xC3\xA9]/'];
        yield 'braced escape below 0x100' => ['pattern' => '/[\x{e9}]/'];
        yield 'outside a class' => ['pattern' => '/é/'];
    }

    /**
     * @return iterable<string, array{pattern: string, id: string}>
     */
    public static function provideUtfVerbPatterns(): iterable
    {
        yield 'class' => ['pattern' => '/(*UTF)[é]/', 'id' => self::IN_CLASS];
        yield 'quantifier' => ['pattern' => '/(*UTF)é+/', 'id' => self::QUANTIFIED];
        yield 'braced escape' => ['pattern' => '/(*UTF)\x{e9}|\x{100}/', 'id' => 'regex.lint.unicode.bracedHexWithoutU'];
        yield 'property' => ['pattern' => '/(*UTF)\p{L}/', 'id' => 'regex.lint.unicode.propertyWithoutU'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideByteQuantifiers(): iterable
    {
        yield 'plus' => ['pattern' => '/é+/'];
        yield 'braces' => ['pattern' => '/é{2,}/'];
        yield 'optional' => ['pattern' => '/€?/'];
        yield 'quoted' => ['pattern' => '/\Qé\E*/'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideCleanQuantifiers(): iterable
    {
        yield 'u flag' => ['pattern' => '/é+/u'];
        yield '(*UTF)' => ['pattern' => '/(*UTF)é+/'];
        yield 'grouped' => ['pattern' => '/(?:é)+/'];
        yield 'ascii' => ['pattern' => '/a+/'];
        yield 'byte escape' => ['pattern' => '/\xA9+/'];
        yield 'no quantifier' => ['pattern' => '/é/'];
        yield 'ascii after a letter' => ['pattern' => '/ab+/'];
        yield 'latin-1 byte' => ['pattern' => "/a\xA9+/"];
    }

    private function violation(string $pattern, string $id): ?RuleViolation
    {
        foreach ($this->lint($pattern) as $violation) {
            if ($id === $violation->id) {
                return $violation;
            }
        }

        return null;
    }

    /**
     * @return array<RuleViolation>
     */
    private function lint(string $pattern): array
    {
        $linter = new PatternLinter();
        Regex::create()->parse($pattern)->accept($linter);

        return $linter->getIssues();
    }
}
