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

namespace RegexParser\Tests\Unit\Lint\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Automata\Unicode\CodePointHelper;
use RegexParser\Lint\Rule\Support\CodePoints;
use RegexParser\NodeVisitor\LinterNodeVisitor;
use RegexParser\Regex;

/**
 * Whether "i" changes what a pattern matches is PCRE's case folding, the
 * same with or without the intl extension: the lint reads case from
 * mbstring, which the library requires.
 */
final class CaseWithoutIntlTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatternsWhoseCaseMatters')]
    public function test_i_is_not_reported_useless_where_pcre_folds_case(string $pattern, string $subject): void
    {
        // PCRE folds the case: the flag changes what the pattern matches.
        $this->assertSame(1, preg_match($pattern, $subject), $pattern);
        $this->assertSame(0, preg_match(str_replace('/iu', '/u', $pattern), $subject), $pattern);

        $this->assertNotContains('regex.lint.flag.useless.i', $this->issueIds($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function providePatternsWhoseCaseMatters(): iterable
    {
        yield 'Latin letter with an accent' => ['pattern' => '/é/iu', 'subject' => 'É'];
        yield 'circled letter, a symbol with case' => ['pattern' => '/Ⓐ/iu', 'subject' => 'ⓐ'];
        yield 'Greek letter' => ['pattern' => '/σ/iu', 'subject' => 'Σ'];
        yield 'titlecase digraph' => ['pattern' => '/ǅ/iu', 'subject' => 'ǆ'];
        yield 'Roman numeral, a number with case' => ['pattern' => '/Ⅻ/iu', 'subject' => 'ⅻ'];
        yield 'range of accented letters' => ['pattern' => '/[é-ë]/iu', 'subject' => 'Ë'];
    }

    #[Test]
    public function test_i_is_reported_useless_where_nothing_has_case(): void
    {
        $this->assertContains('regex.lint.flag.useless.i', $this->issueIds('/١٢٣/iu'));
    }

    /**
     * Without UTF mode PCRE's default tables fold ASCII letters only: a
     * range of Latin-1 bytes has no case to fold.
     */
    #[Test]
    public function test_i_is_reported_useless_on_bytes_without_utf_mode(): void
    {
        $this->assertSame(0, preg_match("/[\xE9-\xEB]/i", "\xCB"));
        $this->assertContains('regex.lint.flag.useless.i', $this->issueIds("/[\xE9-\xEB]/i"));
    }

    #[Test]
    public function test_code_points_are_read_from_utf8(): void
    {
        $this->assertSame(0xE9, CodePoints::fromLiteral('é', true));
        $this->assertSame(0x24B6, CodePoints::fromLiteral('Ⓐ', true));
        $this->assertNull(CodePoints::fromLiteral('ab', true));
        $this->assertSame(0xE9, CodePointHelper::toCodePoint('é'));
        $this->assertNull(CodePointHelper::toCodePoint(''));
        $this->assertSame('é', CodePointHelper::toString(0xE9));
        $this->assertNull(CodePointHelper::toString(0xD800));
    }

    /**
     * Which lint issues a pattern gets does not hang on an optional
     * extension: the only intl call left names the character of an escape
     * PCRE refuses anyway.
     */
    #[Test]
    public function test_lint_rules_do_not_read_intl(): void
    {
        $readers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../../../src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension() && str_contains((string) file_get_contents($file->getPathname()), 'IntlChar')) {
                $readers[] = str_replace('\\', '/', substr($file->getPathname(), (int) strpos($file->getPathname(), 'src/')));
            }
        }
        sort($readers);

        $this->assertSame(['src/Internal/CodePointReader.php', 'src/Lint/Rule/SuspiciousEscapeRule.php'], $readers);
    }

    /**
     * @return list<string>
     */
    private function issueIds(string $pattern): array
    {
        $linter = new LinterNodeVisitor();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        return array_values(array_map(static fn ($issue): string => $issue->id, $linter->getIssues()));
    }
}
