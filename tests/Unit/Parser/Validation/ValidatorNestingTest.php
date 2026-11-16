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

namespace PHPRegex\Tests\Unit\Parser\Validation;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationErrorCategory;
use PHPRegex\Parser\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValidatorNestingTest extends TestCase
{
    /**
     * PCRE refuses the level past 250 once it has read the opener, before
     * the spaces "x" skips: preg_match() on PCRE2 10.49 reports "parentheses
     * are too deeply nested at offset 255", right after the 251st "(".
     */
    #[Test]
    public function test_the_level_past_the_limit_is_refused_before_the_spaces_x_skips(): void
    {
        $pattern = '/(?x)'.str_repeat('(', 250).'(   a'.str_repeat(')', 251).'/';
        $result = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.49', 'runtime_pcre_validation' => false])->validate($pattern);

        $this->assertSame([ErrorCode::GroupNestedTooDeep, 255], [$result->errorCode, $result->offset]);
    }

    /**
     * Past the parser's own limit on nesting, 512 levels by default, PCRE
     * has refused the level past 250, or an error before it, already: that
     * error is the verdict, not the limit. Each row is what preg_match()
     * reports on PHP 8.4.27 with PCRE2 10.49.
     *
     * @param array{max_recursion_depth?: int} $options
     */
    #[Test]
    #[DataProvider('provideErrorsBeforeTheParserLimit')]
    public function test_an_error_pcre_meets_before_the_parser_limit_is_the_verdict(string $pattern, array $options, ErrorCode $code, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), 'Oracle: the engine refuses the pattern.');

        $result = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.49', 'runtime_pcre_validation' => false] + $options)->validate($pattern);

        $this->assertSame([$code, $offset], [$result->errorCode, $result->offset]);
        $this->assertNotSame(ValidationErrorCategory::Limit, $result->category, 'A verdict, not a limit.');
    }

    /**
     * @return iterable<string, array{pattern: string, options: array{max_recursion_depth?: int}, code: ErrorCode, offset: int}>
     */
    public static function provideErrorsBeforeTheParserLimit(): iterable
    {
        yield 'parentheses nested 600 levels' => ['pattern' => self::deep('', 600), 'options' => [], 'code' => ErrorCode::GroupNestedTooDeep, 'offset' => 251];
        yield 'parentheses nested 512 levels' => ['pattern' => self::deep('', 512), 'options' => [], 'code' => ErrorCode::GroupNestedTooDeep, 'offset' => 251];
        yield 'parentheses nested 300 levels under a limit of 260' => ['pattern' => self::deep('', 300), 'options' => ['max_recursion_depth' => 260], 'code' => ErrorCode::GroupNestedTooDeep, 'offset' => 251];
        yield 'reversed range before 600 levels' => ['pattern' => self::deep('[z-a]', 600), 'options' => [], 'code' => ErrorCode::RangeReversed, 'offset' => 4];
        yield 'reversed count before 600 levels' => ['pattern' => self::deep('a{3,2}', 600), 'options' => [], 'code' => ErrorCode::QuantifierInvalidRange, 'offset' => 5];
        // Below the parser's limit, an error the parser meets before the
        // level PCRE refuses comes first too.
        yield 'unmatched parenthesis before 300 levels' => ['pattern' => self::deep(')', 300), 'options' => [], 'code' => ErrorCode::GroupUnmatchedClose, 'offset' => 1];
        yield 'condition with no name before 300 levels' => ['pattern' => self::deep('(?(', 300), 'options' => [], 'code' => ErrorCode::ConditionalInvalid, 'offset' => 3];
    }

    /**
     * PCRE refuses the level past 250 once it has read its opener whole, at
     * the start of its body: past "(?:" or "(?<n>", and after an opener it
     * refuses for itself. Each row is what preg_match() reports with the
     * PCRE2 bundled by PHP 8.2 and 8.4 (10.40 and 10.44 agree) and with
     * PCRE2 10.49; "after" is what follows the opener of that level.
     */
    #[Test]
    #[DataProvider('provideOpenersOfTheLevelPastTheLimit')]
    public function test_the_level_past_the_limit_is_refused_past_its_opener(string $opener, string $after, ErrorCode $code, int $bundled, int $newer): void
    {
        $pattern = '/'.str_repeat('(', 250).$opener.$after.'/';

        foreach (['10.44' => $bundled, '10.49' => $newer] as $release => $offset) {
            $result = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => $release, 'runtime_pcre_validation' => false])->validate($pattern);

            $this->assertSame([$code, $offset], [$result->errorCode, $result->offset], $opener.' on PCRE2 '.$release);
        }
    }

    /**
     * @return iterable<string, array{opener: string, after: string, code: ErrorCode, bundled: int, newer: int}>
     */
    public static function provideOpenersOfTheLevelPastTheLimit(): iterable
    {
        $closed = 'a'.str_repeat(')', 251);
        // One ")" too many: the parser stops at the end, and the level past
        // the limit is looked for in what it read.
        $unmatched = 'a'.str_repeat(')', 252);
        $deeper = str_repeat('(', 349).'a'.str_repeat(')', 600);

        yield 'unknown character after "(?"' => ['opener' => '(?%', 'after' => $closed, 'code' => ErrorCode::GroupSyntax, 'bundled' => 252, 'newer' => 253];
        yield 'unknown character after "(?P"' => ['opener' => '(?P', 'after' => $closed, 'code' => ErrorCode::GroupSyntax, 'bundled' => 253, 'newer' => 254];
        yield 'unknown option letter' => ['opener' => '(?iz', 'after' => $closed, 'code' => ErrorCode::GroupSyntax, 'bundled' => 253, 'newer' => 254];
        yield 'name never closed' => ['opener' => '(?<', 'after' => $closed, 'code' => ErrorCode::GroupNameUnterminated, 'bundled' => 254, 'newer' => 254];
        yield 'quoted name never closed' => ['opener' => "(?'", 'after' => $closed, 'code' => ErrorCode::GroupNameUnterminated, 'bundled' => 254, 'newer' => 254];
        yield 'name starting with a digit' => ['opener' => '(?<1', 'after' => $closed, 'code' => ErrorCode::GroupNameInvalid, 'bundled' => 253, 'newer' => 254];
        yield 'non-capturing group, a ")" too many' => ['opener' => '(?:', 'after' => $unmatched, 'code' => ErrorCode::GroupNestedTooDeep, 'bundled' => 253, 'newer' => 253];
        yield 'named group, a ")" too many' => ['opener' => '(?<n>', 'after' => $unmatched, 'code' => ErrorCode::GroupNestedTooDeep, 'bundled' => 255, 'newer' => 255];
        yield 'group with options, a ")" too many' => ['opener' => '(?i:', 'after' => $unmatched, 'code' => ErrorCode::GroupNestedTooDeep, 'bundled' => 254, 'newer' => 254];
        yield 'conditional, a ")" too many' => ['opener' => '(?(1)', 'after' => $unmatched, 'code' => ErrorCode::GroupNestedTooDeep, 'bundled' => 255, 'newer' => 255];
        yield 'group after a comment, a ")" too many' => ['opener' => '(?#c)(', 'after' => $unmatched, 'code' => ErrorCode::GroupNestedTooDeep, 'bundled' => 256, 'newer' => 256];
        yield 'non-capturing group past the parser limit' => ['opener' => '(?:', 'after' => $deeper, 'code' => ErrorCode::GroupNestedTooDeep, 'bundled' => 253, 'newer' => 253];
        yield 'named group past the parser limit' => ['opener' => '(?<n>', 'after' => $deeper, 'code' => ErrorCode::GroupNestedTooDeep, 'bundled' => 255, 'newer' => 255];
        // What follows the opener is not read before that level is refused:
        // a class never closed there is never reached.
        yield 'non-capturing group, then a class never closed' => ['opener' => '(?:', 'after' => '[', 'code' => ErrorCode::GroupNestedTooDeep, 'bundled' => 253, 'newer' => 253];
        yield 'named group, then a class never closed' => ['opener' => '(?<n>', 'after' => '[', 'code' => ErrorCode::GroupNestedTooDeep, 'bundled' => 255, 'newer' => 255];
        yield 'quoted name, then a class never closed' => ['opener' => "(?'n'", 'after' => '[', 'code' => ErrorCode::GroupNestedTooDeep, 'bundled' => 255, 'newer' => 255];
        yield 'group with options, then a class never closed' => ['opener' => '(?i:', 'after' => '[', 'code' => ErrorCode::GroupNestedTooDeep, 'bundled' => 254, 'newer' => 254];
        yield 'conditional, then a class never closed' => ['opener' => '(?(1)', 'after' => '[', 'code' => ErrorCode::GroupNestedTooDeep, 'bundled' => 255, 'newer' => 255];
        yield 'alphabetic lookahead, then a class never closed' => ['opener' => '(*pla:', 'after' => '[', 'code' => ErrorCode::GroupNestedTooDeep, 'bundled' => 256, 'newer' => 256];
        yield 'named group, then a property never closed' => ['opener' => '(?<n>', 'after' => '\\p{', 'code' => ErrorCode::GroupNestedTooDeep, 'bundled' => 255, 'newer' => 255];
        yield 'non-capturing group, then a comment never closed' => ['opener' => '(?:', 'after' => '(?#', 'code' => ErrorCode::GroupNestedTooDeep, 'bundled' => 253, 'newer' => 253];
    }

    /**
     * A limit met before the level PCRE refuses stays the verdict: what
     * lies between the two is not read.
     */
    #[Test]
    public function test_the_parser_limit_met_before_the_level_pcre_refuses_is_the_verdict(): void
    {
        $result = RegexParser::create(['cache' => null, 'max_recursion_depth' => 100])->validate(self::deep('', 300));

        $this->assertSame([ValidationErrorCategory::Limit, ErrorCode::NestingTooDeep], [$result->category, $result->errorCode]);
    }

    /**
     * Off a walk from the pattern root, the level refused is not counted:
     * a tree as deep as the limit visited next is taken.
     */
    #[Test]
    public function test_a_level_refused_off_a_walk_is_not_counted(): void
    {
        $validator = new Validator();

        try {
            self::nested(251)->accept($validator);
            $this->fail('A group past the nesting limit is refused.');
        } catch (SemanticErrorException $error) {
            $this->assertSame(ErrorCode::GroupNestedTooDeep, $error->errorCode);
        }

        $refused = null;

        try {
            self::nested(250)->accept($validator);
        } catch (SemanticErrorException $error) {
            $refused = $error->errorCode;
        }

        $this->assertNull($refused);
    }

    private static function deep(string $before, int $levels): string
    {
        return '/'.$before.str_repeat('(', $levels).'a'.str_repeat(')', $levels).'/';
    }

    private static function nested(int $depth): GroupNode
    {
        $node = new GroupNode(new LiteralNode('a', 0, 0), GroupType::NonCapturing);
        for ($level = 1; $level < $depth; $level++) {
            $node = new GroupNode($node, GroupType::NonCapturing);
        }

        return $node;
    }
}
