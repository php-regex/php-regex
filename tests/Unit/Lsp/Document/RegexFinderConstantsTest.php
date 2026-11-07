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
use PHPRegex\Parser\Text\Path;
use PHPRegex\Tests\TestUtils\FileUri;
use PHPRegex\Tests\TestUtils\TemporaryDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern another file of the workspace writes, named through one of its
 * constants, is found at the argument naming it: the argument as written,
 * the constant and where its literal stands.
 */
final class RegexFinderConstantsTest extends TestCase
{
    private const PATTERNS = <<<'CODE'
        <?php

        namespace App\Support;

        final class Patterns
        {
            public const RE = '/(a/';

            public const RES = ['/(b/', '/(c/'];
        }

        final class Other
        {
            public const RE = '/(a/';
        }
        CODE;

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = Path::fromHost(sys_get_temp_dir()).'/regex-parser-finder-constants-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/src', 0o700, true);
        file_put_contents($this->root.'/src/Patterns.php', self::PATTERNS);
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->root);
    }

    #[Test]
    public function test_each_argument_naming_a_pattern_of_another_file_is_found_as_written(): void
    {
        $content = <<<'CODE'
            <?php
            use App\Support\Patterns;
            use App\Support\Other;
            preg_match((Patterns::RE), $s);
            preg_match(Other::RE, $s);
            preg_replace(Patterns::RES, '', $s);
            CODE;

        $found = array_map(
            static fn (RegexOccurrence $occurrence): array => [$occurrence->pattern, $occurrence->literal, $occurrence->line, $occurrence->writtenElsewhere],
            (new RegexFinder())->find($content, $this->declarations()),
        );

        $this->assertSame([
            ['/(a/', '(Patterns::RE)', 4, 'Patterns::RE, written in src/Patterns.php on line 7'],
            ['/(a/', 'Other::RE', 5, 'Other::RE, written in src/Patterns.php on line 14'],
            ['/(b/', 'Patterns::RES', 6, 'Patterns::RES, written in src/Patterns.php on line 9'],
            ['/(c/', 'Patterns::RES', 6, 'Patterns::RES, written in src/Patterns.php on line 9'],
        ], $found);
    }

    /**
     * "@regex-ignore" naming no issue on the line of the argument silences
     * the pattern there, as on the line of a literal.
     */
    #[Test]
    public function test_a_marker_on_the_line_of_the_argument_silences_it(): void
    {
        $content = "<?php\nuse App\\Support\\Patterns;\nuse App\\Support\\Other;\npreg_match(Patterns::RE, \$s); // @regex-ignore\npreg_match(Other::RE, \$s);\n";

        $this->assertSame(
            [[4, true], [5, false]],
            array_map(static fn (RegexOccurrence $occurrence): array => [$occurrence->line, $occurrence->ignored], (new RegexFinder())->find($content, $this->declarations())),
        );
    }

    /**
     * The declarations read after a first search are read by the next one,
     * the constants of the workspace with them: the call to the function
     * now declared is read, and it names the pattern first.
     */
    #[Test]
    public function test_a_declaration_read_after_a_search_is_read_by_the_next(): void
    {
        $content = "<?php\nuse App\\Support\\Patterns;\nApp\\grep(Patterns::RE);\npreg_match(Patterns::RE, \$s);\n";
        $finder = new RegexFinder();
        $declarations = $this->declarations();

        $this->assertSame([4], array_map(static fn (RegexOccurrence $occurrence): int => $occurrence->line, $finder->find($content, $declarations)));

        $declarations->readDocument(FileUri::of($this->root.'/src/Grep.php'), "<?php\nnamespace App;\nuse PHPRegex\\Parser\\Attribute\\RegexPattern;\nfunction grep(#[RegexPattern] string \$regex) {}\n");

        // The literal is reported once, at the first argument naming it.
        $this->assertSame([3], array_map(static fn (RegexOccurrence $occurrence): int => $occurrence->line, $finder->find($content, $declarations)));
    }

    private function declarations(): PatternDeclarations
    {
        $declarations = new PatternDeclarations();
        $declarations->scanWorkspace($this->root, ['src'], []);

        return $declarations;
    }
}
