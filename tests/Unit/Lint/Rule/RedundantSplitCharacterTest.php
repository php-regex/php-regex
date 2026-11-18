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
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without UTF mode a class holds each byte of a multibyte character: "[äö]"
 * holds 0xC3 twice. A byte two different characters share is no duplicate
 * the author wrote, and the class is reported once, for the missing u. A
 * character written twice, or a byte written beside one, is a duplicate.
 * Each row checks over every byte that the class without the duplicate is
 * the same class.
 */
final class RedundantSplitCharacterTest extends TestCase
{
    /**
     * @param list<string> $issues
     */
    #[Test]
    #[DataProvider('provideClasses')]
    public function test_a_byte_shared_by_two_characters_is_no_duplicate(string $pattern, string $without, array $issues): void
    {
        for ($byte = 0; $byte <= 0xFF; $byte++) {
            $this->assertSame(preg_match($without, \chr($byte)), preg_match($pattern, \chr($byte)), \sprintf('byte 0x%02X', $byte));
        }

        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);
        $reported = array_values(array_unique(array_map(static fn (object $issue): string => $issue->id, $linter->getIssues())));
        sort($reported);

        $this->assertSame($issues, $reported, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, without: string, issues: list<string>}>
     */
    public static function provideClasses(): iterable
    {
        $multibyte = 'regex.lint.unicode.multibyteInClassWithoutU';
        $redundant = 'regex.lint.charclass.redundant';

        yield 'two characters sharing their first byte' => ['pattern' => '/[äö]/', 'without' => '/[\xC3\xA4\xB6]/', 'issues' => [$multibyte]];
        yield 'two characters sharing their first byte, caseless' => ['pattern' => '/[aäö]/i', 'without' => '/[a\xC3\xA4\xB6]/i', 'issues' => [$multibyte]];
        yield 'three characters sharing their first byte' => ['pattern' => '/[äöü]/', 'without' => '/[\xC3\xA4\xB6\xBC]/', 'issues' => [$multibyte]];
        yield 'a range between two characters' => ['pattern' => '/[à-é]/', 'without' => '/[\xA0-\xC3]/', 'issues' => [$multibyte]];
        yield 'a character written twice' => ['pattern' => '/[ää]/', 'without' => '/[\xC3\xA4]/', 'issues' => [$redundant, $multibyte]];
        yield 'a character written twice, caseless' => ['pattern' => '/[aää]/i', 'without' => '/[a\xC3\xA4]/i', 'issues' => [$redundant, $multibyte]];
        yield 'a character written twice in UTF mode' => ['pattern' => '/[ää]/u', 'without' => '/[ä]/u', 'issues' => [$redundant]];
        yield 'a character written again after another' => ['pattern' => '/[äöä]/', 'without' => '/[\xC3\xA4\xB6]/', 'issues' => [$redundant, $multibyte]];
        yield 'a byte written beside a character starting with it' => ['pattern' => '/[aä\xC3]/', 'without' => '/[a\xC3\xA4]/', 'issues' => [$redundant, $multibyte]];
        yield 'a byte written beside a character starting with it, caseless' => ['pattern' => '/[aä\xC3]/i', 'without' => '/[a\xC3\xA4]/i', 'issues' => [$redundant, $multibyte]];
        yield 'a byte written twice' => ['pattern' => '/[\xC3\xC3]/', 'without' => '/[\xC3]/', 'issues' => [$redundant]];
        yield 'an escape written twice' => ['pattern' => '/[\t\t]/', 'without' => '/[\t]/', 'issues' => [$redundant]];
        // A range starts at the last byte of a character and ends at the first byte of the next.
        yield 'two ranges between different characters' => ['pattern' => '/[à-éè-ê]/', 'without' => '/[\xA0-\xC3]/', 'issues' => [$multibyte]];
        yield 'a range written twice' => ['pattern' => '/[à-éà-é]/', 'without' => '/[\xA0-\xC3]/', 'issues' => [$redundant, $multibyte]];
        yield 'a character written again after a range from it' => ['pattern' => '/[à-éà]/', 'without' => '/[\xA0-\xC3]/', 'issues' => [$redundant, $multibyte]];
        yield 'a byte written before a range between characters' => ['pattern' => '/[\xC3à-é]/', 'without' => '/[\xA0-\xC3]/', 'issues' => [$redundant, $multibyte]];
    }
}
