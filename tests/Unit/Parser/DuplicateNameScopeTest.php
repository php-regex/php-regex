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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Tests\TestUtils\PcreMessageCodes;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How far the "J" modifier reaches.
 *
 * "J" lets two groups share a name. Like every inline modifier, "(?J)" holds
 * until the end of the group that encloses it and "(?J:...)" only inside its
 * own — so a name repeated outside either of those is still a duplicate.
 *
 * Each case is checked against what PCRE does with the same pattern.
 */
final class DuplicateNameScopeTest extends TestCase
{
    #[Test]
    #[DataProvider('provideScopes')]
    public function test_a_repeated_name_is_refused_where_pcre_refuses_it(string $pattern, bool $accepted): void
    {
        $this->assertSame($accepted, $this->pcreAccepts($pattern), 'The expectation does not match PCRE.');

        $regex = Regex::create(['cache' => new NullCache()]);

        if (!$accepted) {
            $this->expectException(ParserException::class);
            $this->expectExceptionMessage('Duplicate group name');
        }

        $ast = $regex->parse($pattern);

        $this->assertSame($pattern, $ast->delimiter.$ast->source.$ast->delimiter.$ast->flags);
    }

    /**
     * @return iterable<string, array{pattern: string, accepted: bool}>
     */
    public static function provideScopes(): iterable
    {
        yield 'no J at all' => ['pattern' => '/(?<n>a)(?<n>b)/', 'accepted' => false];
        yield 'J for the rest of the pattern' => ['pattern' => '/(?J)(?<n>a)(?<n>b)/', 'accepted' => true];
        yield 'J inside its own group' => ['pattern' => '/(?J:(?<n>a)(?<n>b))/', 'accepted' => true];

        // Both of these leave the reach of the J before repeating the name.
        yield 'J left behind by a scoped group' => ['pattern' => '/(?J:(?<n>a))(?<n>b)/', 'accepted' => false];
        yield 'J left behind by the enclosing group' => [
            'pattern' => '/(?:(?J)(?<n>a))(?<n>b)/',
            'accepted' => false,
        ];

        yield 'J still in force inside a nested group' => [
            'pattern' => '/(?J)(?:(?<n>a))(?<n>b)/',
            'accepted' => true,
        ];
    }

    /**
     * Group names are shared across the body of an alphabetic assertion as
     * across the body of "(?=": a name repeated inside it, or a "(?J)" set
     * inside it and holding there only, is judged against the whole pattern.
     * The code and the offset are PCRE's, read from the running engine.
     */
    #[Test]
    #[DataProvider('provideNamesAcrossAlphabeticBodies')]
    public function test_a_name_repeated_across_an_alphabetic_body_is_refused_where_pcre_refuses_it(string $pattern, ?ErrorCode $code, ?int $offset): void
    {
        $pcre = PcreMessageCodes::read(PcreMessageCodes::warningOf($pattern) ?? 'compiles');
        $this->assertSame($offset, $pcre['offset'], \sprintf('Oracle: %s (%s).', $pattern, $pcre['message']));
        if (null !== $code) {
            $this->assertContains($code->value, PcreMessageCodes::CODES[$pcre['message']] ?? [], \sprintf('Oracle: %s does not name "%s".', $code->value, $pcre['message']));
        }

        $result = Regex::create(['cache' => new NullCache()])->validate($pattern);

        $this->assertSame([null === $code, $code, $offset], [$result->isValid, $result->errorCode, $result->offset], \sprintf('%s: %s', $pattern, (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string, code: ?ErrorCode, offset: ?int}>
     */
    public static function provideNamesAcrossAlphabeticBodies(): iterable
    {
        // PCRE: "two named subpatterns have the same name (PCRE2_DUPNAMES not set)".
        yield 'name repeated inside a body' => ['pattern' => '/(?<n>a)(*pla:(?<n>b))/', 'code' => ErrorCode::GroupDuplicateName, 'offset' => 18];
        yield 'name repeated after a body' => ['pattern' => '/(*pla:(?<n>a))(?<n>b)/', 'code' => ErrorCode::GroupDuplicateName, 'offset' => 19];
        yield 'J left behind by a body' => ['pattern' => '/(*pla:(?J)(?<n>a))(?<n>b)/', 'code' => ErrorCode::GroupDuplicateName, 'offset' => 23];
        yield 'J left behind by an atomic body' => ['pattern' => '/(*atomic:(?J)(?<n>a))(?<n>b)/', 'code' => ErrorCode::GroupDuplicateName, 'offset' => 26];
        yield 'name repeated inside a short lookahead' => ['pattern' => '/(?<n>a)(?*(?<n>b))/', 'code' => ErrorCode::GroupDuplicateName, 'offset' => 15];
        yield 'name repeated inside a negative lookahead' => ['pattern' => '/(?<n>a)(*nla:(?<n>b))/', 'code' => ErrorCode::GroupDuplicateName, 'offset' => 18];
        yield 'name repeated inside a lookbehind' => ['pattern' => '/(?<n>a)(*plb:(?<n>b))/', 'code' => ErrorCode::GroupDuplicateName, 'offset' => 18];
        yield 'name repeated inside an atomic body' => ['pattern' => '/(?<n>a)(*atomic:(?<n>b))/', 'code' => ErrorCode::GroupDuplicateName, 'offset' => 21];
        yield 'name repeated inside a script run' => ['pattern' => '/(?<n>a)(*sr:(?<n>b))/', 'code' => ErrorCode::GroupDuplicateName, 'offset' => 17];
        yield 'non-ASCII name repeated inside a body' => ['pattern' => "/(?<n\u{e9}>a)(*pla:(?<n\u{e9}>b))/u", 'code' => ErrorCode::GroupDuplicateName, 'offset' => 22];
        yield 'non-ASCII name repeated after a body' => ['pattern' => "/(*pla:(?<n\u{e9}>a))(?<n\u{e9}>b)/u", 'code' => ErrorCode::GroupDuplicateName, 'offset' => 23];
        // PCRE: "different names for subpatterns of the same number are not allowed".
        yield 'branch reset naming a body group differently' => ['pattern' => '/(?|(?<n>a)|(*pla:(?<m>b)))/', 'code' => ErrorCode::GroupNameConflict, 'offset' => 22];
        yield 'branch reset naming a body group first' => ['pattern' => '/(?|(*pla:(?<n>a))|(?<m>b))/', 'code' => ErrorCode::GroupNameConflict, 'offset' => 23];
        // Accepted by PCRE: J in force across the body, or a body that
        // shares the name of its branch-reset twin.
        yield 'J set before the body' => ['pattern' => '/(?J)(?<n>a)(*pla:(?<n>b))/', 'code' => null, 'offset' => null];
        yield 'J scoped around the body' => ['pattern' => '/(?J:(*pla:(?<n>a))(?<n>b))/', 'code' => null, 'offset' => null];
        yield 'J set inside the body for the body' => ['pattern' => '/(*pla:(?J)(?<n>a)(?<n>b))/', 'code' => null, 'offset' => null];
        yield 'branch reset with the same name in a body' => ['pattern' => '/(?|(?<n>a)|(*pla:(?<n>b)))/', 'code' => null, 'offset' => null];
        // Refused where PCRE refuses them already: kept as guards.
        yield 'name repeated inside one body' => ['pattern' => '/(*pla:(?<n>a)(?<n>b))/', 'code' => ErrorCode::GroupDuplicateName, 'offset' => 18];
        yield 'name repeated inside a lookahead spelled (?=' => ['pattern' => '/(?<n>a)(?=(?<n>b))/', 'code' => ErrorCode::GroupDuplicateName, 'offset' => 15];
        yield 'branch reset naming a lookahead group spelled (?= differently' => ['pattern' => '/(?|(?<n>a)|(?=(?<m>b)))/', 'code' => ErrorCode::GroupNameConflict, 'offset' => 19];
        yield 'J left behind by a lookahead spelled (?=' => ['pattern' => '/(?=(?J)(?<n>a))(?<n>b)/', 'code' => ErrorCode::GroupDuplicateName, 'offset' => 20];
    }

    /**
     * "(?^)" turns off i, m, n, r, s, x and xx only: J stays in force, from
     * the J flag or from "(?J)", so a name may still be repeated after it,
     * in a "(?^:...)" group or in an alphabetic body. PCRE compiles each
     * pattern; "(?-J)" turns it off (guard).
     */
    #[Test]
    #[DataProvider('provideCaretResets')]
    public function test_a_caret_reset_leaves_repeated_names_allowed(string $pattern, ?ErrorCode $code, ?int $offset): void
    {
        $pcre = PcreMessageCodes::read(PcreMessageCodes::warningOf($pattern) ?? 'compiles');
        $this->assertSame($offset, $pcre['offset'], \sprintf('Oracle: %s (%s).', $pattern, $pcre['message']));
        if (null !== $code) {
            $this->assertContains($code->value, PcreMessageCodes::CODES[$pcre['message']] ?? [], \sprintf('Oracle: %s does not name "%s".', $code->value, $pcre['message']));
        }

        $regex = Regex::create(['cache' => new NullCache()]);
        $result = $regex->validate($pattern);

        $this->assertSame([null === $code, $code, $offset], [$result->isValid, $result->errorCode, $result->offset], \sprintf('%s: %s', $pattern, (string) $result->error));
        if (null === $code) {
            $ast = $regex->parse($pattern);
            $this->assertSame($pattern, $ast->delimiter.$ast->source.$ast->delimiter.$ast->flags);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, code: ?ErrorCode, offset: ?int}>
     */
    public static function provideCaretResets(): iterable
    {
        yield 'J flag, caret in a body' => ['pattern' => '/(?<m>a)(*pla:(?^)(?<m>b))/J', 'code' => null, 'offset' => null];
        yield 'inline J, caret in a body' => ['pattern' => '/(?J)(?<m>a)(*pla:(?^)(?<m>b))/', 'code' => null, 'offset' => null];
        yield 'J flag, caret between the names' => ['pattern' => '/(?<m>a)(?^)(?<m>b)/J', 'code' => null, 'offset' => null];
        yield 'inline J, caret group around the second name' => ['pattern' => '/(?J)(?<m>a)(?^:(?<m>b))/', 'code' => null, 'offset' => null];
        yield 'J flag, caret first, the second name in an empty branch' => ['pattern' => '~(?^)(?<o>||)|(?<o>|(?xx)) ~J', 'code' => null, 'offset' => null];
        yield 'inline J, caret before both names' => ['pattern' => '/(?J)(?^)(?<m>a)(?<m>b)/', 'code' => null, 'offset' => null];
        yield 'scoped J, caret group around both names' => ['pattern' => '/(?J:(?^:(?<m>a)(?<m>b)))/', 'code' => null, 'offset' => null];
        yield 'inline J, caret with a letter between the names' => ['pattern' => '/(?J)(?<m>a)(?^i)(?<m>b)/', 'code' => null, 'offset' => null];
        // Refused where PCRE refuses it already: kept as a guard.
        // PCRE: "two named subpatterns have the same name (PCRE2_DUPNAMES not set)".
        yield 'J turned off by name' => ['pattern' => '/(?J)(?<m>a)(?-J)(?<m>b)/', 'code' => ErrorCode::GroupDuplicateName, 'offset' => 21];
    }

    private function pcreAccepts(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);
        $accepts = false !== @preg_match($pattern, '');
        restore_error_handler();

        return $accepts;
    }
}
