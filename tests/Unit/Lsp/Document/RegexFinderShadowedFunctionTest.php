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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An unqualified call in a namespace reaches that namespace's function
 * first, marked or not, and the global one only when there is none: a
 * namespace's own grep() shadows a global pattern function grep(), as
 * `regex lint` reads it.
 */
final class RegexFinderShadowedFunctionTest extends TestCase
{
    private const CALLS = "<?php\nnamespace App;\ngrep('/unqualified/');\n\\grep('/global/');\n";

    private const SHADOW = "<?php\nnamespace App;\nfunction grep(string \$subject) {}\n";

    #[Test]
    public function test_a_configured_global_function_is_read_until_a_namespace_shadows_it(): void
    {
        $finder = new RegexFinder(PatternFunctionRegistry::create([], ['grep']));
        $declarations = new PatternDeclarations();

        $this->assertSame(['/unqualified/', '/global/'], self::patterns($finder->find(self::CALLS, $declarations)));

        $declarations->readDocument('untitled:Shadow', self::SHADOW);
        $this->assertSame(['/global/'], self::patterns($finder->find(self::CALLS, $declarations)));

        $declarations->closeDocument('untitled:Shadow');
        $this->assertSame(['/unqualified/', '/global/'], self::patterns($finder->find(self::CALLS, $declarations)));
    }

    #[Test]
    public function test_a_declared_global_function_is_shadowed_the_same_way(): void
    {
        $finder = new RegexFinder();
        $declarations = new PatternDeclarations();
        $declarations->readDocument('untitled:Global', "<?php\nuse PHPRegex\\Parser\\Attribute\\RegexPattern;\nfunction grep(#[RegexPattern] string \$regex) {}\n");

        $this->assertSame(['/unqualified/', '/global/'], self::patterns($finder->find(self::CALLS, $declarations)));

        $declarations->readDocument('untitled:Shadow', self::SHADOW);
        $this->assertSame(['/global/'], self::patterns($finder->find(self::CALLS, $declarations)));
    }

    /**
     * The workspace files and the libraries shadow as the open documents
     * do; a file read again or forgotten is followed.
     */
    #[Test]
    public function test_a_file_on_the_disk_shadows_too(): void
    {
        $root = str_replace('\\', '/', sys_get_temp_dir()).'/regex-parser-lsp-shadow-'.bin2hex(random_bytes(6));
        mkdir($root.'/src', 0o700, true);
        mkdir($root.'/vendor/acme', 0o700, true);

        try {
            file_put_contents($root.'/vendor/acme/Shadow.php', self::SHADOW);
            $finder = new RegexFinder(PatternFunctionRegistry::create([], ['grep']));
            $declarations = new PatternDeclarations();
            $declarations->scanWorkspace($root, ['src'], ['vendor']);
            $declarations->scanLibraries($root.'/vendor');

            $this->assertSame(['/global/'], self::patterns($finder->find(self::CALLS, $declarations)));

            $this->assertTrue($declarations->forgetFile($root.'/vendor/acme/Shadow.php'));
            $this->assertSame(['/unqualified/', '/global/'], self::patterns($finder->find(self::CALLS, $declarations)));

            file_put_contents($root.'/src/Shadow.php', self::SHADOW);
            $this->assertTrue($declarations->readFile($root.'/src/Shadow.php'));
            $this->assertSame(['/global/'], self::patterns($finder->find(self::CALLS, $declarations)));
        } finally {
            foreach (['/vendor/acme/Shadow.php', '/src/Shadow.php'] as $file) {
                if (is_file($root.$file)) {
                    unlink($root.$file);
                }
            }
            foreach (['/vendor/acme', '/vendor', '/src', ''] as $directory) {
                rmdir($root.$directory);
            }
        }
    }

    /**
     * The text an editor saves is read as given, not from the disk: it
     * shadows, then stops shadowing once the function is gone from it.
     */
    #[Test]
    public function test_a_saved_text_shadows_until_its_function_is_removed(): void
    {
        $root = str_replace('\\', '/', sys_get_temp_dir()).'/regex-parser-lsp-saved-'.bin2hex(random_bytes(6));
        mkdir($root.'/src', 0o700, true);
        $file = $root.'/src/Shadow.php';
        $empty = "<?php\nnamespace App;\n";

        try {
            file_put_contents($file, $empty);
            $finder = new RegexFinder(PatternFunctionRegistry::create([], ['grep']));
            $declarations = new PatternDeclarations();
            $declarations->scanWorkspace($root, ['src'], []);

            $this->assertSame(['/unqualified/', '/global/'], self::patterns($finder->find(self::CALLS, $declarations)));

            // The disk still holds no function: only the saved text does.
            $declarations->readFile($file, self::SHADOW);
            $this->assertSame(['/global/'], self::patterns($finder->find(self::CALLS, $declarations)));

            $declarations->readFile($file, $empty);
            $this->assertSame(['/unqualified/', '/global/'], self::patterns($finder->find(self::CALLS, $declarations)));
        } finally {
            unlink($file);
            rmdir($root.'/src');
            rmdir($root);
        }
    }

    /**
     * @param list<RegexOccurrence> $occurrences
     *
     * @return list<string>
     */
    private static function patterns(array $occurrences): array
    {
        return array_map(static fn (RegexOccurrence $occurrence): string => $occurrence->pattern, $occurrences);
    }
}
