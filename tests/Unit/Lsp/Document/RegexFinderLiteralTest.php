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

namespace PHPRegex\Tests\Unit\Lsp\Document;

use PHPRegex\LanguageServer\Document\PatternDeclarations;
use PHPRegex\LanguageServer\Document\RegexFinder;
use PHPRegex\LanguageServer\Document\RegexOccurrence;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An occurrence is the one string literal that holds the pattern the lint
 * reads: the range a diagnostic or an edit lands in.
 */
final class RegexFinderLiteralTest extends TestCase
{
    /**
     * @return iterable<string, array{code: string, expected: list<array{pattern: string, literal: string}>}>
     */
    public static function provideLiterals(): iterable
    {
        yield 'an escaped quote stays in the literal' => [
            'code' => "preg_match('/it\\'s/', \$s);",
            'expected' => [['pattern' => "/it's/", 'literal' => "'/it\\'s/'"]],
        ];
        yield 'an escaped double quote stays in the literal' => [
            'code' => 'preg_match("/a\\"b/", $s);',
            'expected' => [['pattern' => '/a"b/', 'literal' => '"/a\\"b/"']],
        ];
        // Strings::match() appends "u" to the pattern with utf8: true; the
        // literal spells the rest.
        yield 'a modifier the call adds' => [
            'code' => "use Nette\\Utils\\Strings;\nStrings::match(\$s, '/x/', utf8: true);",
            'expected' => [['pattern' => '/x/u', 'literal' => "'/x/'"]],
        ];
        yield 'each literal of a constant array' => [
            'code' => "final class A { private const RES = ['/one/', '/two/']; public function f(\$s) { return preg_replace(self::RES, '', \$s); } }",
            'expected' => [['pattern' => '/one/', 'literal' => "'/one/'"], ['pattern' => '/two/', 'literal' => "'/two/'"]],
        ];
        yield 'a variable read by two calls, once' => [
            'code' => "function f(\$s) { \$re = '/local/'; return preg_match(\$re, \$s) + preg_match(\$re, \$s); }",
            'expected' => [['pattern' => '/local/', 'literal' => "'/local/'"]],
        ];
        // No single literal holds these: an edit of one piece would break
        // the code.
        yield 'a pattern concatenated at the call' => ['code' => "preg_match('/a/' . 'b/', \$s);", 'expected' => []];
        yield 'a literal concatenated with letters' => ['code' => "preg_match('/a/' . 'i', \$s);", 'expected' => []];
        yield 'a heredoc' => ['code' => "preg_match(<<<'RE'\n/heredoc/\nRE, \$s);", 'expected' => []];
        yield 'an unterminated literal' => ['code' => "preg_match('/open/", 'expected' => []];
    }

    /**
     * @param list<array{pattern: string, literal: string}> $expected
     */
    #[Test]
    #[DataProvider('provideLiterals')]
    public function test_an_occurrence_is_the_literal_that_spells_the_pattern(string $code, array $expected): void
    {
        $content = "<?php\n".$code."\n";

        $occurrences = (new RegexFinder())->find($content);

        $this->assertSame($expected, array_map(static fn (RegexOccurrence $occurrence): array => ['pattern' => $occurrence->pattern, 'literal' => $occurrence->literal], $occurrences));
        foreach ($occurrences as $occurrence) {
            $this->assertSame($occurrence->literal, substr($content, $occurrence->byteOffset, \strlen($occurrence->literal)));
        }
    }

    #[Test]
    public function test_the_registry_given_names_the_calls_read(): void
    {
        $content = "<?php\nuse Nette\\Utils\\Strings;\nStrings::match(\$s, '/nette/');\npreg_match('/native/', \$s);\n";

        $occurrences = (new RegexFinder(PatternFunctionRegistry::create([])))->find($content);

        $this->assertSame(['/native/'], array_map(static fn (RegexOccurrence $occurrence): string => $occurrence->pattern, $occurrences));
    }

    /**
     * The declarations change between two reads: the second read sees them.
     */
    #[Test]
    public function test_a_declaration_read_between_two_finds_counts(): void
    {
        $content = "<?php\nnamespace App;\ngrep('/declared/');\n";
        $finder = new RegexFinder();
        $declarations = new PatternDeclarations();

        $this->assertSame([], $finder->find($content, $declarations));

        $declarations->readDocument('untitled:Untitled-1', "<?php\nnamespace App;\nuse PHPRegex\\Parser\\Attribute\\RegexPattern;\nfunction grep(#[RegexPattern] string \$regex) {}\n");
        $this->assertCount(1, $finder->find($content, $declarations));

        $declarations->readDocument('untitled:Untitled-1', "<?php\nnamespace App;\nuse PHPRegex\\Parser\\Attribute\\RegexPattern;\nfunction grep(string \$subject) {}\nfunction other(#[RegexPattern] string \$regex) {}\n");
        $this->assertSame([], $finder->find($content, $declarations));
    }
}
