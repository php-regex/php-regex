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
use PHPRegex\Parser\Exception\ParserException;
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

    private function pcreAccepts(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);
        $accepts = false !== @preg_match($pattern, '');
        restore_error_handler();

        return $accepts;
    }
}
