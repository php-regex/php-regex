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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An edit inside the literal a call takes whole as its pattern is read
 * without reading the document again: the tokens around it are the same,
 * and the patterns of that literal are its new value with the modifiers
 * the call adds. Every other edit reads the document whole. Either way the
 * patterns found are those a fresh read of the edited text finds.
 */
final class RegexFinderEditTest extends TestCase
{
    #[Test]
    #[DataProvider('provideEdits')]
    public function test_an_edit_finds_what_a_fresh_read_finds(string $before, string $after, bool $readWhole): void
    {
        $finder = new RegexFinder();
        $declarations = new PatternDeclarations();
        $found = $finder->find($before, $declarations);
        $reads = self::reads($finder);

        $edited = $finder->findAfterEdit($before, $found, $after, $declarations);

        $this->assertEquals((new RegexFinder())->find($after, new PatternDeclarations()), $edited);
        $this->assertSame($readWhole ? $reads + 1 : $reads, self::reads($finder), $readWhole ? 'The edit was not read whole.' : 'The edit read the document whole.');
    }

    /**
     * @return iterable<string, array{before: string, after: string, readWhole: bool}>
     */
    public static function provideEdits(): iterable
    {
        $around = "<?php\n// @regex-ignore-next-line\npreg_match('/x+/', \$s);\nif (PHP_VERSION_ID >= 80400) {\n    preg_split('/,/', \$s);\n}\n";
        $call = static fn (string $literal): string => $around.'preg_match('.$literal.", \$s); preg_replace('/é/u', 'e', \$s); preg_match('/z/', \$s);\nnew \\SplFileInfo('/tmp');\npreg_match('/tail/', \$s);\n";

        // Read in place: an edit inside a literal a call takes whole.
        yield 'a character added' => ['before' => $call("'/ab/'"), 'after' => $call("'/abc/'"), 'readWhole' => false];
        yield 'a character removed' => ['before' => $call("'/abc/'"), 'after' => $call("'/ac/'"), 'readWhole' => false];
        yield 'a character past ASCII added, the patterns after it on its line moved' => ['before' => $call("'/a/u'"), 'after' => $call("'/a\u{1F600}é/u'"), 'readWhole' => false];
        yield 'a modifier added' => ['before' => $call("'/a/'"), 'after' => $call("'/a/i'"), 'readWhole' => false];
        yield 'double quotes' => ['before' => $call('"/a\\\\d/"'), 'after' => $call('"/a\\\\d+\\t/"'), 'readWhole' => false];
        yield 'a binary string' => ['before' => $call("b'/a/'"), 'after' => $call("b'/ab/'"), 'readWhole' => false];
        yield 'a literal over two lines' => ['before' => $call("'/a\n b/x'"), 'after' => $call("'/ab\n b/x'"), 'readWhole' => false];
        yield 'on the second line of a literal' => ['before' => $call("'/a\n b/x'"), 'after' => $call("'/a\n bc/x'"), 'readWhole' => false];
        yield 'a named argument' => ['before' => "<?php\npreg_match(subject: \$s, pattern: '/a/');\npreg_match('/b/', \$s);\n", 'after' => "<?php\npreg_match(subject: \$s, pattern: '/ab/');\npreg_match('/b/', \$s);\n", 'readWhole' => false];
        yield 'a static method named by a keyword' => ['before' => "<?php\nuse Composer\\Pcre\\Preg;\nPreg::match('/a/', \$s);\n", 'after' => "<?php\nuse Composer\\Pcre\\Preg;\nPreg::match('/a+/', \$s);\n", 'readWhole' => false];
        yield 'a call that adds a modifier' => ['before' => "<?php\nuse Nette\\Utils\\Strings;\nStrings::match(\$s, '/a/', utf8: true);\n", 'after' => "<?php\nuse Nette\\Utils\\Strings;\nStrings::match(\$s, '/ab/', utf8: true);\n", 'readWhole' => false];
        yield 'a constructor' => ['before' => "<?php\nnew \\Symfony\\Component\\Validator\\Constraints\\Regex('/a/');\n", 'after' => "<?php\nnew \\Symfony\\Component\\Validator\\Constraints\\Regex('/a\\d/');\n", 'readWhole' => false];
        yield 'after an argument holding parentheses' => ['before' => "<?php\nuse Nette\\Utils\\Strings;\nStrings::match(trim(\$s), '/a/', utf8: true);\n", 'after' => "<?php\nuse Nette\\Utils\\Strings;\nStrings::match(trim(\$s), '/ab/', utf8: true);\n", 'readWhole' => false];
        yield 'beside a line break the literal holds' => ['before' => $call("'/a\r\n/x'"), 'after' => $call("'/ab\r\n/x'"), 'readWhole' => false];
        yield 'beside a pattern the file proves' => ['before' => "<?php\n\$re = '/v/';\npreg_match(\$re, \$s);\npreg_match('/a/', \$s);\n", 'after' => "<?php\n\$re = '/v/';\npreg_match(\$re, \$s);\npreg_match('/ab/', \$s);\n", 'readWhole' => false];

        // Read whole.
        yield 'the same text' => ['before' => $call("'/a/'"), 'after' => $call("'/a/'"), 'readWhole' => false];
        yield 'outside any literal' => ['before' => $call("'/a/'"), 'after' => str_replace('$s)', '$t)', $call("'/a/'")), 'readWhole' => true];
        yield 'a new call' => ['before' => $call("'/a/'"), 'after' => $call("'/a/'")."preg_match('/new/', \$s);\n", 'readWhole' => true];
        yield 'the literal closed early' => ['before' => $call("'/a/'"), 'after' => $call("'/a', '/'"), 'readWhole' => true];
        yield 'a quote escaped' => ['before' => $call("'/a\\'/'"), 'after' => $call("'/a\\\\'/'"), 'readWhole' => true];
        yield 'a line break added' => ['before' => $call("'/a/x'"), 'after' => $call("'/a\n/x'"), 'readWhole' => true];
        yield 'a carriage return added' => ['before' => $call("'/a/x'"), 'after' => $call("'/a\r/x'"), 'readWhole' => true];
        yield 'a line break erased' => ['before' => $call("'/a\n/x'"), 'after' => $call("'/a/x'"), 'readWhole' => true];
        yield 'a variable interpolated' => ['before' => $call('"/a/"'), 'after' => $call('"/a$x/"'), 'readWhole' => true];
        yield 'no delimiter left' => ['before' => $call("'/a/'"), 'after' => $call("'a/'"), 'readWhole' => true];
        yield 'emptied' => ['before' => $call("'/'"), 'after' => $call("''"), 'readWhole' => true];
        yield 'an assigned literal' => ['before' => "<?php\n\$re = '/a/';\npreg_match(\$re, \$s);\n", 'after' => "<?php\n\$re = '/ab/';\npreg_match(\$re, \$s);\n", 'readWhole' => true];
        yield 'an array item' => ['before' => "<?php\npreg_replace(['/a/', '/b/'], '', \$s);\n", 'after' => "<?php\npreg_replace(['/a/', '/bc/'], '', \$s);\n", 'readWhole' => true];
        yield 'a define' => ['before' => "<?php\ndefine('RE', '/a/');\npreg_match(RE, \$s);\n", 'after' => "<?php\ndefine('RE', '/ab/');\npreg_match(RE, \$s);\n", 'readWhole' => true];
        yield 'a version compared' => ['before' => "<?php\nif (version_compare(PHP_VERSION, '8.0', '>=')) {\n    preg_match('/a{,2}/', \$s);\n}\n", 'after' => "<?php\nif (version_compare(PHP_VERSION, '8.4', '>=')) {\n    preg_match('/a{,2}/', \$s);\n}\n", 'readWhole' => true];
        yield 'a concatenation' => ['before' => "<?php\npreg_match('/a/' . 'i', \$s);\n", 'after' => "<?php\npreg_match('/ab/' . 'i', \$s);\n", 'readWhole' => true];
        yield 'a call left open' => ['before' => "<?php\npreg_match('/a/'", 'after' => "<?php\npreg_match('/ab/'", 'readWhole' => true];
        yield 'a parenthesized literal' => ['before' => "<?php\npreg_match(('/a/'), \$s);\n", 'after' => "<?php\npreg_match(('/ab/'), \$s);\n", 'readWhole' => true];
    }

    /**
     * Edits drawn at random over the fixture files: a byte added, removed
     * or replaced inside a pattern's literal, a quote or a line break
     * among them, or anywhere in the file. Each finds what a fresh read
     * finds.
     */
    #[Test]
    public function test_random_edits_find_what_a_fresh_read_finds(): void
    {
        mt_srand(20261019);
        $files = [...glob(\dirname(__DIR__, 3).'/Fixtures/Lint/*.php') ?: [], ...glob(\dirname(__DIR__, 3).'/Fixtures/Extractor/*.php') ?: []];
        $this->assertNotEmpty($files);
        $inPlace = 0;
        $edits = 0;
        foreach ($files as $file) {
            $text = (string) file_get_contents($file);
            $finder = new RegexFinder();
            $found = $finder->find($text);
            for ($round = 0; $round < 12; $round++) {
                $after = self::edit($text, $found);
                $reads = self::reads($finder);
                $edited = $finder->findAfterEdit($text, $found, $after);

                $this->assertEquals((new RegexFinder())->find($after), $edited, basename($file).': '.json_encode([$text, $after]));
                $inPlace += $reads === self::reads($finder) ? 1 : 0;
                $edits++;
                [$text, $found] = [$after, $edited];
            }
        }

        $this->assertGreaterThan(100, $edits);
        $this->assertGreaterThan(20, $inPlace);
    }

    /**
     * @param list<RegexOccurrence> $found
     */
    private static function edit(string $text, array $found): string
    {
        $bytes = ['a', '\\', "'", '"', "\n", '$', 'é', ')', ' '];
        $byte = $bytes[mt_rand(0, \count($bytes) - 1)];
        $occurrence = [] !== $found && mt_rand(0, 3) > 0 ? $found[mt_rand(0, \count($found) - 1)] : null;
        $position = null === $occurrence
            ? mt_rand(0, \strlen($text))
            : $occurrence->byteOffset + mt_rand(1, max(1, \strlen($occurrence->literal) - 1));

        return match (mt_rand(0, 2)) {
            0 => substr($text, 0, $position).$byte.substr($text, $position),
            1 => substr($text, 0, $position).substr($text, $position + 1),
            default => substr($text, 0, $position).$byte.substr($text, $position + 1),
        };
    }

    private static function reads(RegexFinder $finder): int
    {
        $reads = (new \ReflectionProperty(RegexFinder::class, 'reads'))->getValue($finder);
        self::assertIsInt($reads);

        return $reads;
    }
}
