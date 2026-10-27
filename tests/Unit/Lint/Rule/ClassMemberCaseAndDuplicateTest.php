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

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two rules that read the members of a class: "flag i is useless" and
 * "an element adds no new match". Each verdict is held to the engine,
 * every byte matched with the flag and without it, with the element and
 * without it: a rule may stay silent where it cannot read a member, never
 * claim what the engine contradicts.
 */
final class ClassMemberCaseAndDuplicateTest extends TestCase
{
    private const USELESS_I = 'regex.lint.flag.useless.i';

    private const DUPLICATE = 'regex.lint.charclass.duplicateChars';

    /**
     * @param bool $useless what the engine says: the pattern matches the same bytes without i
     */
    #[Test]
    #[DataProvider('provideCaselessClasses')]
    public function test_flag_i_is_called_useless_only_where_the_engine_agrees(string $pattern, bool $useless, bool $reported): void
    {
        $this->assertSame($useless, self::sameBytes($pattern, substr($pattern, 0, -1)), 'Oracle: the engine with i and without it.');

        $this->assertSame($reported, null !== $this->violation($pattern, self::USELESS_I));
    }

    /**
     * @return iterable<string, array{pattern: string, useless: bool, reported: bool}>
     */
    public static function provideCaselessClasses(): iterable
    {
        // \cA is \x01: the range holds the capitals, which i folds onto
        // the small letters the class does not hold.
        yield 'a range from a control character to the capitals' => ['pattern' => '/[\cA-Z]/i', 'useless' => false, 'reported' => false];
        // Both cases are in the range already: the flag adds nothing, but
        // the rule does not read an endpoint written \cA, and stays silent.
        yield 'a range from a control character over both cases' => ['pattern' => '/[\cA-z0]/i', 'useless' => true, 'reported' => false];
        // \cA to \cZ is \x01 to \x1A: no letter, read as PCRE reads it.
        yield 'a range of control characters' => ['pattern' => '/[\cA-\cZ]/i', 'useless' => true, 'reported' => true];
        yield 'a range of digits' => ['pattern' => '/[\x01-9]/i', 'useless' => true, 'reported' => true];
        yield 'a range from a hex escape to the capitals' => ['pattern' => '/[\x01-Z]/i', 'useless' => false, 'reported' => false];
    }

    /**
     * An element the others already match adds nothing: the engine
     * matches the same bytes without it.
     *
     * @param string $without the class without the element the rule names
     */
    #[Test]
    #[DataProvider('provideDuplicateElements')]
    public function test_an_element_the_others_match_is_a_duplicate(string $pattern, string $without): void
    {
        $this->assertTrue(self::sameBytes($pattern, $without), 'Oracle: the element adds no byte.');

        $this->assertNotNull($this->violation($pattern, self::DUPLICATE));
    }

    /**
     * @return iterable<string, array{pattern: string, without: string}>
     */
    public static function provideDuplicateElements(): iterable
    {
        yield 'a digit beside [:digit:]' => ['pattern' => '/[[:digit:]0]/', 'without' => '/[[:digit:]]/'];
        yield 'a small letter beside [:lower:]' => ['pattern' => '/[[:lower:]q]/', 'without' => '/[[:lower:]]/'];
        yield 'a capital beside [:upper:]' => ['pattern' => '/[[:upper:]Q]/', 'without' => '/[[:upper:]]/'];
        yield 'a letter beside [:alpha:]' => ['pattern' => '/[[:alpha:]q]/', 'without' => '/[[:alpha:]]/'];
        yield 'an underscore beside [:word:]' => ['pattern' => '/[[:word:]_]/', 'without' => '/[[:word:]]/'];
        yield 'a hex letter beside [:xdigit:]' => ['pattern' => '/[[:xdigit:]f]/', 'without' => '/[[:xdigit:]]/'];
        yield 'a letter beside what is no digit' => ['pattern' => '/[\Dq]/', 'without' => '/[\D]/'];
        yield 'a digit beside what is no space' => ['pattern' => '/[\S7]/', 'without' => '/[\S]/'];
    }

    /**
     * A range the rule cannot read, from a control character, is no proof
     * of a duplicate: the rule says nothing of the members it would cover.
     */
    #[Test]
    public function test_a_range_from_a_control_character_proves_no_duplicate(): void
    {
        $this->assertTrue(self::sameBytes('/[\cA-z0]/', '/[\cA-z]/'), 'Oracle: the digit is in the range.');

        $this->assertNull($this->violation('/[\cA-z0]/', self::DUPLICATE));
    }

    /**
     * Whether the two patterns match the same bytes, each of the 256.
     */
    private static function sameBytes(string $pattern, string $other): bool
    {
        for ($byte = 0; $byte < 256; $byte++) {
            if (preg_match($pattern, \chr($byte)) !== preg_match($other, \chr($byte))) {
                return false;
            }
        }

        return true;
    }

    private function violation(string $pattern, string $id): ?RuleViolation
    {
        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        foreach ($linter->getIssues() as $violation) {
            if ($id === $violation->id) {
                return $violation;
            }
        }

        return null;
    }
}
