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

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Parser\Cache\NullCache;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "(?(VERSION>=10.4)yes|no)" branches on the version of PCRE reading the
 * pattern, and PCRE compares two ways only: "=" and ">=".
 *
 * The parser reads the other comparisons so that such a pattern still
 * produces a tree to look at; the validator is what says they will not
 * compile. Each case is checked against PCRE's own answer first.
 */
final class VersionConditionValidationTest extends TestCase
{
    #[Test]
    #[DataProvider('provideVersionConditions')]
    public function test_a_version_condition_is_valid_where_pcre_compiles_it(string $pattern, bool $valid): void
    {
        $this->assertSame($valid, $this->pcreCompiles($pattern), 'The expectation does not match PCRE.');

        $result = Regex::create(['cache' => new NullCache()])->validate($pattern);

        $this->assertSame($valid, $result->isValid, (string) $result->error);
    }

    /**
     * @return iterable<string, array{pattern: string, valid: bool}>
     */
    public static function provideVersionConditions(): iterable
    {
        yield 'at least' => ['pattern' => '/(?(VERSION>=10.4)y|n)/', 'valid' => true];
        yield 'exactly' => ['pattern' => '/(?(VERSION=10.4)y|n)/', 'valid' => true];

        // PCRE offers no other comparison.
        yield 'strictly above' => ['pattern' => '/(?(VERSION>10.4)y|n)/', 'valid' => false];
        yield 'strictly below' => ['pattern' => '/(?(VERSION<10.4)y|n)/', 'valid' => false];
        yield 'at most' => ['pattern' => '/(?(VERSION<=10.4)y|n)/', 'valid' => false];
        yield 'not equal' => ['pattern' => '/(?(VERSION!=10.4)y|n)/', 'valid' => false];
    }

    #[Test]
    public function test_the_error_names_the_comparisons_pcre_makes(): void
    {
        $result = Regex::create(['cache' => new NullCache()])->validate('/(?(VERSION>10.4)y|n)/');

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('PCRE compares with "=" or ">="', (string) $result->error);
    }

    private function pcreCompiles(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);
        $compiles = false !== @preg_match($pattern, '');
        restore_error_handler();

        return $compiles;
    }
}
