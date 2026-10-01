<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Lint\Rule;

use PhpRegex\Linter\PatternLinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "\NN" of two digits or more that names no group is an octal escape, not a
 * reference: "\11" is a tab, and in UTF mode "\666" is U+01B6. Only "\1" to
 * "\9", and "\g" forms, always refer to a group.
 */
final class UndefinedBackrefOctalTest extends TestCase
{
    #[Test]
    #[DataProvider('provideOctalEscapes')]
    public function test_an_octal_escape_is_not_a_missing_reference(string $pattern): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), $pattern);

        $this->assertNotContains('regex.lint.backref.undefined', $this->lint($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideOctalEscapes(): iterable
    {
        yield 'tab' => ['pattern' => '/a\\11b/'];
        yield 'code point in UTF mode' => ['pattern' => '/\\666/u'];
        yield 'octal then a literal digit' => ['pattern' => '/(a)\\128/'];
    }

    #[Test]
    #[DataProvider('provideMissingReferences')]
    public function test_a_reference_to_a_missing_group_is_still_reported(string $pattern): void
    {
        $this->assertContains('regex.lint.backref.undefined', $this->lint($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideMissingReferences(): iterable
    {
        yield 'one digit' => ['pattern' => '/(a)\\2/'];
        yield 'braced g' => ['pattern' => '/(a)\\g{12}/'];
        yield 'digits that are no octal' => ['pattern' => '/(a)\\89/'];
    }

    /**
     * @return list<string>
     */
    private function lint(string $pattern): array
    {
        $visitor = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($visitor);

        return array_values(array_map(static fn (object $issue): string => $issue->id, $visitor->getIssues()));
    }
}
