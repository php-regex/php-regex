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
 * A file that declares no class may still declare what another file is
 * read with: a global constant, or a function of a namespace named as
 * one of PHP's the reading follows. The server keeps such a file, and an
 * edit to what it declares changes the documents.
 */
final class PatternDeclarationsGlobalConstantTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = Path::fromHost(sys_get_temp_dir()).'/regex-parser-lsp-global-constant-'.bin2hex(random_bytes(6));
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

    #[Test]
    public function test_a_file_declaring_only_global_constants_is_kept(): void
    {
        $this->write('src/constants.php', self::constants("'/x/'"));
        $declarations = new PatternDeclarations();
        $declarations->scanWorkspace($this->root, ['src'], []);

        $this->assertTrue($declarations->constants()->declaresGlobal('app\\RE'));
        $this->assertSame('/x/', self::valueOf($declarations, 'app\\RE'));
        $this->assertTrue($declarations->constants()->declaresGlobal('APP_RE'));
        $this->assertSame('/defined/', self::valueOf($declarations, 'APP_RE'));
    }

    /**
     * @return iterable<string, array{before: string, after: string, changed: bool}>
     */
    public static function provideEditsOfAGlobalConstant(): iterable
    {
        yield 'another value' => ['before' => "'/x/'", 'after' => "'/y/'", 'changed' => true];
        yield 'the flags' => ['before' => "'/x/'", 'after' => "'/x/i'", 'changed' => true];
        yield 'one string made a ternary' => ['before' => "'/x/'", 'after' => "\\PHP_INT_SIZE === 8 ? '/x/' : '/y/'", 'changed' => true];
        yield 'an arm of a ternary' => ['before' => "\\PHP_INT_SIZE === 8 ? '/x/' : '/y/'", 'after' => "\\PHP_INT_SIZE === 8 ? '/x/' : '/z/'", 'changed' => true];
        yield 'a value the file does not prove' => ['before' => "'/x/'", 'after' => "strtoupper('/x/')", 'changed' => true];
        yield 'the same value' => ['before' => "'/x/'", 'after' => "'/x/'", 'changed' => false];
        yield 'the same value, moved along the line' => ['before' => "'/x/'", 'after' => "    '/x/'", 'changed' => false];
    }

    #[Test]
    #[DataProvider('provideEditsOfAGlobalConstant')]
    public function test_an_edit_changes_the_documents_when_it_changes_a_global_constant(string $before, string $after, bool $changed): void
    {
        $constants = $this->write('src/constants.php', self::constants($before));
        $declarations = new PatternDeclarations();
        $declarations->scanWorkspace($this->root, ['src'], []);

        $this->assertSame($changed, $declarations->readFile($constants, self::constants($after)));
    }

    #[Test]
    public function test_a_global_constant_declared_or_removed_changes_the_documents(): void
    {
        $constants = $this->write('src/constants.php', "<?php\nnamespace App;\n");
        $declarations = new PatternDeclarations();
        $declarations->scanWorkspace($this->root, ['src'], []);

        $this->assertFalse($declarations->constants()->declaresGlobal('app\\RE'));
        $this->assertTrue($declarations->readFile($constants, self::constants("'/x/'")), 'Declared.');
        $this->assertTrue($declarations->constants()->declaresGlobal('app\\RE'));
        $this->assertTrue($declarations->readFile($constants, "<?php\nnamespace App;\n"), 'Removed.');
        $this->assertFalse($declarations->constants()->declaresGlobal('app\\RE'));
    }

    #[Test]
    public function test_a_deleted_file_takes_its_global_constants_with_it(): void
    {
        $constants = $this->write('src/constants.php', self::constants("'/x/'"));
        $declarations = new PatternDeclarations();
        $declarations->scanWorkspace($this->root, ['src'], []);

        $this->assertTrue($declarations->forgetFile($constants));
        $this->assertFalse($declarations->constants()->declaresGlobal('app\\RE'));
    }

    /**
     * An open document stands for its file: the constant edited there,
     * not saved yet, is the one read.
     */
    #[Test]
    public function test_a_global_constant_edited_in_an_open_document_changes_the_documents(): void
    {
        $constants = $this->write('src/constants.php', self::constants("'/x/'"));
        $declarations = new PatternDeclarations();
        $declarations->scanWorkspace($this->root, ['src'], []);
        $uri = FileUri::of($constants);

        $this->assertFalse($declarations->readDocument($uri, self::constants("'/x/'")), 'Opened as its file is.');
        $this->assertTrue($declarations->readDocument($uri, self::constants("'/y/'")));
        $this->assertSame('/y/', self::valueOf($declarations, 'app\\RE'));
        $this->assertFalse($declarations->readDocument($uri, self::constants("'/y/'")), 'Nothing changed.');
        $this->assertTrue($declarations->closeDocument($uri));
        $this->assertSame('/x/', self::valueOf($declarations, 'app\\RE'));
    }

    /**
     * A function of a namespace named as preg_quote() is the one a call
     * of that namespace reaches: a file declaring nothing else is kept,
     * and the function declared or removed changes the documents.
     */
    #[Test]
    public function test_a_file_declaring_only_a_function_named_as_one_of_php_is_kept(): void
    {
        $functions = $this->write('src/functions.php', self::functions('preg_quote'));
        $declarations = new PatternDeclarations();
        $declarations->scanWorkspace($this->root, ['src'], []);

        $this->assertTrue($declarations->constants()->declaresFunction('app\\preg_quote'));
        $this->assertFalse($declarations->readFile($functions, self::functions('preg_quote')), 'Nothing changed.');
        $this->assertTrue($declarations->readFile($functions, self::functions('sprintf')), 'Another function.');
        $this->assertFalse($declarations->constants()->declaresFunction('app\\preg_quote'));
        $this->assertTrue($declarations->constants()->declaresFunction('app\\sprintf'));
        $this->assertTrue($declarations->readFile($functions, self::functions('helper')), 'No longer one the reading follows.');
        $this->assertFalse($declarations->constants()->declaresFunction('app\\sprintf'));
    }

    /**
     * The value the project reads a global constant with, if it proves one.
     */
    private static function valueOf(PatternDeclarations $declarations, string $key): ?string
    {
        return $declarations->constants()->globalConstant($key)?->value;
    }

    private static function constants(string $value): string
    {
        return "<?php\nnamespace App;\nconst RE = ".$value.";\n\\define('APP_RE', '/defined/');\n";
    }

    private static function functions(string $name): string
    {
        return "<?php\nnamespace App;\nfunction ".$name."(string \$text): string\n{\n    return \$text;\n}\n";
    }

    private function write(string $relative, string $content): string
    {
        $path = $this->root.'/'.$relative;
        file_put_contents($path, $content);

        return $path;
    }
}
