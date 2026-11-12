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
use PHPRegex\Parser\Text\Path;
use PHPRegex\Tests\TestUtils\FileUri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A constant whose value is a ternary, a short ternary or a null
 * coalescing holds one of several patterns: each arm is part of what the
 * constant says. An edit that changes one arm of a constant of another
 * file changes what the documents naming it read: the declarations say
 * so, as they do for a constant holding one string.
 *
 * PHP 8.4 (php -r, 64 bits): const RE = PHP_INT_SIZE === 8 ? '/a/' :
 * '/b/' is "/a/", and "/b/" where the integers are of another size; ''
 * ?: '/kept/' is "/kept/".
 */
final class PatternDeclarationsBranchingConstantTest extends TestCase
{
    private const READER = "<?php\nnamespace App;\nfinal class Reader\n{\n    public const RE = Patterns::RE;\n}\n";

    private string $root;

    protected function setUp(): void
    {
        $this->root = Path::fromHost(sys_get_temp_dir()).'/regex-parser-lsp-branching-constant-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/src', 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->root.'/src') ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                unlink($this->root.'/src/'.$entry);
            }
        }
        rmdir($this->root.'/src');
        rmdir($this->root);
    }

    /**
     * The value of the constant before and after the edit, and whether
     * what the constant says changed. An arm moved along its line says
     * the same.
     *
     * @return iterable<string, array{before: string, after: string, changed: bool}>
     */
    public static function provideEditsOfABranchingConstant(): iterable
    {
        $ternary = "\\PHP_INT_SIZE === 8 ? '/a/' : '/b/'";

        yield 'the else arm of a ternary' => ['before' => $ternary, 'after' => "\\PHP_INT_SIZE === 8 ? '/a/' : '/c/'", 'changed' => true];
        yield 'the then arm of a ternary' => ['before' => $ternary, 'after' => "\\PHP_INT_SIZE === 8 ? '/c/' : '/b/'", 'changed' => true];
        yield 'the flags of one arm' => ['before' => $ternary, 'after' => "\\PHP_INT_SIZE === 8 ? '/a/' : '/b/i'", 'changed' => true];
        yield 'an arm added in a ternary of its own' => ['before' => $ternary, 'after' => "\\PHP_INT_SIZE === 8 ? '/a/' : (\\PHP_INT_SIZE === 4 ? '/b/' : '/c/')", 'changed' => true];
        yield 'an arm of an inner ternary' => ['before' => "\\PHP_INT_SIZE === 8 ? '/a/' : (\\PHP_INT_SIZE === 4 ? '/b/' : '/c/')", 'after' => "\\PHP_INT_SIZE === 8 ? '/a/' : (\\PHP_INT_SIZE === 4 ? '/b/' : '/d/')", 'changed' => true];
        yield 'one string made a ternary' => ['before' => "'/a/'", 'after' => $ternary, 'changed' => true];
        yield 'a ternary made its first arm' => ['before' => $ternary, 'after' => "'/a/'", 'changed' => true];
        yield 'an arm made a constant' => ['before' => $ternary, 'after' => "\\PHP_INT_SIZE === 8 ? '/a/' : self::OTHER", 'changed' => true];
        // '' ?: '/kept/' is "/kept/", '' ?: '/other/' is "/other/".
        yield 'the arm a short ternary keeps' => ['before' => "self::EMPTY ?: '/kept/'", 'after' => "self::EMPTY ?: '/other/'", 'changed' => true];
        yield 'the same ternary' => ['before' => $ternary, 'after' => $ternary, 'changed' => false];
        yield 'the same ternary, its arms moved along the line' => ['before' => $ternary, 'after' => "\\PHP_INT_SIZE === 8  ?  '/a/'  :  '/b/'", 'changed' => false];
    }

    #[Test]
    #[DataProvider('provideEditsOfABranchingConstant')]
    public function test_an_edit_changes_the_documents_when_it_changes_an_arm_of_a_constant(string $before, string $after, bool $changed): void
    {
        $patterns = $this->write('src/Patterns.php', self::patterns($before));
        $this->write('src/Reader.php', self::READER);
        $declarations = new PatternDeclarations();
        $declarations->scanWorkspace($this->root, ['src'], []);

        $this->assertTrue($declarations->constants()->has('app\\patterns'));
        $this->assertTrue($declarations->constants()->has('app\\reader'));

        $this->assertSame($changed, $declarations->readFile($patterns, self::patterns($after)));
    }

    /**
     * The value of an enum case is read the same way.
     */
    #[Test]
    public function test_an_edit_changes_the_documents_when_it_changes_an_arm_of_an_enum_case(): void
    {
        $kind = $this->write('src/Kind.php', self::kind("'/a/' : '/b/'"));
        $declarations = new PatternDeclarations();
        $declarations->scanWorkspace($this->root, ['src'], []);

        $this->assertTrue($declarations->constants()->has('app\\kind'));
        $this->assertFalse($declarations->readFile($kind, self::kind("'/a/'  :  '/b/'")), 'The same arms, moved along the line.');
        $this->assertTrue($declarations->readFile($kind, self::kind("'/a/' : '/c/'")));
    }

    /**
     * An open document stands for its file: the arm edited there, not
     * saved yet, changes the documents as well.
     */
    #[Test]
    public function test_an_arm_edited_in_an_open_document_changes_the_documents(): void
    {
        $patterns = $this->write('src/Patterns.php', self::patterns("\\PHP_INT_SIZE === 8 ? '/a/' : '/b/'"));
        $this->write('src/Reader.php', self::READER);
        $declarations = new PatternDeclarations();
        $declarations->scanWorkspace($this->root, ['src'], []);
        $uri = FileUri::of($patterns);

        $this->assertFalse($declarations->readDocument($uri, self::patterns("\\PHP_INT_SIZE === 8 ? '/a/' : '/b/'")), 'Opened as its file is.');
        $this->assertTrue($declarations->readDocument($uri, self::patterns("\\PHP_INT_SIZE === 8 ? '/a/' : '/c/'")));
        $this->assertFalse($declarations->readDocument($uri, self::patterns("\\PHP_INT_SIZE === 8 ? '/a/' : '/c/'")), 'Nothing changed.');
    }

    private static function patterns(string $value): string
    {
        return "<?php\nnamespace App;\nfinal class Patterns\n{\n    public const EMPTY = '';\n    public const OTHER = '/other/';\n    public const RE = ".$value.";\n}\n";
    }

    private static function kind(string $arms): string
    {
        return "<?php\nnamespace App;\nenum Kind: string\n{\n    case Re = \\PHP_INT_SIZE === 8 ? ".$arms.";\n}\n";
    }

    private function write(string $relative, string $content): string
    {
        $path = $this->root.'/'.$relative;
        file_put_contents($path, $content);

        return $path;
    }
}
