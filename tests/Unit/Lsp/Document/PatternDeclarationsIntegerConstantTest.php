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
 * An integer constant is part of what a pattern says: joined to a string
 * it reads as PHP writes it, so '/a{' . Limits::MAX . '}/' is "/a{3}/"
 * while MAX is 3 and "/a{4}/" once it is 4 (PHP 8.4: '/a{' . 3 . '}/' is
 * "/a{3}/"). An edit that changes the integer of a constant of another
 * file changes what the documents naming it read: the declarations say
 * so, as they do for a constant holding a string.
 */
final class PatternDeclarationsIntegerConstantTest extends TestCase
{
    private const READER = "<?php\nnamespace App;\nfinal class Patterns\n{\n    public const RE = '/a{' . Limits::MAX . '}/';\n}\n";

    private string $root;

    protected function setUp(): void
    {
        $this->root = Path::fromHost(sys_get_temp_dir()).'/regex-parser-lsp-integer-constant-'.bin2hex(random_bytes(6));
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
     * What a pattern reads of the constant: its integer, or that it is
     * none. PHP 8.4: '/a{' . 4 . '}/' is "/a{4}/", -3 gives "/a{-3}/"; a
     * float, a boolean and null are not read as an integer is. 0x3, 0b11
     * and 3 are the same integer, "3".
     *
     * @return iterable<string, array{before: string, after: string, changed: bool}>
     */
    public static function provideIntegerConstantEdits(): iterable
    {
        yield 'another integer' => ['before' => '3', 'after' => '4', 'changed' => true];
        yield 'its sign' => ['before' => '3', 'after' => '-3', 'changed' => true];
        yield 'zero' => ['before' => '3', 'after' => '0', 'changed' => true];
        yield 'from zero' => ['before' => '0', 'after' => '3', 'changed' => true];
        yield 'a float of the same value' => ['before' => '3', 'after' => '3.0', 'changed' => true];
        yield 'from a float to the integer' => ['before' => '3.0', 'after' => '3', 'changed' => true];
        yield 'a boolean' => ['before' => '1', 'after' => 'true', 'changed' => true];
        yield 'null' => ['before' => '0', 'after' => 'null', 'changed' => true];
        yield 'a string of the same digits' => ['before' => '3', 'after' => "'3'", 'changed' => true];
        yield 'the same integer' => ['before' => '3', 'after' => '3', 'changed' => false];
        yield 'the same integer moved along its line' => ['before' => '3', 'after' => '  3', 'changed' => false];
        yield 'the same integer written in hexadecimal' => ['before' => '3', 'after' => '0x3', 'changed' => false];
        yield 'the same integer written in binary' => ['before' => '3', 'after' => '0b11', 'changed' => false];
    }

    #[Test]
    #[DataProvider('provideIntegerConstantEdits')]
    public function test_an_edit_changes_the_documents_when_it_changes_the_integer_of_a_constant(string $before, string $after, bool $changed): void
    {
        $limits = $this->write('src/Limits.php', self::limits($before));
        $this->write('src/Patterns.php', self::READER);
        $declarations = new PatternDeclarations();
        $declarations->scanWorkspace($this->root, ['src'], []);

        $this->assertTrue($declarations->constants()->has('app\\limits'));
        $this->assertTrue($declarations->constants()->has('app\\patterns'));

        $this->assertSame($changed, $declarations->readFile($limits, self::limits($after)));
    }

    /**
     * An open document stands for its file: the integer edited there,
     * not saved yet, changes the documents as well.
     */
    #[Test]
    public function test_an_integer_edited_in_an_open_document_changes_the_documents(): void
    {
        $limits = $this->write('src/Limits.php', self::limits('3'));
        $this->write('src/Patterns.php', self::READER);
        $declarations = new PatternDeclarations();
        $declarations->scanWorkspace($this->root, ['src'], []);
        $uri = FileUri::of($limits);

        $this->assertFalse($declarations->readDocument($uri, self::limits('3')), 'Opened as its file is.');
        $this->assertTrue($declarations->readDocument($uri, self::limits('4')));
        $this->assertFalse($declarations->readDocument($uri, self::limits('4')), 'Nothing changed.');
    }

    private static function limits(string $value): string
    {
        return "<?php\nnamespace App;\nfinal class Limits\n{\n    public const MAX = ".$value.";\n}\n";
    }

    private function write(string $relative, string $content): string
    {
        $path = $this->root.'/'.$relative;
        file_put_contents($path, $content);

        return $path;
    }
}
