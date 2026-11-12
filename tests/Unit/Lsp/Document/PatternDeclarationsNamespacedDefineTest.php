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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A define() written by its bare name in a namespace declares its
 * constant as long as no file declares a function of that name there:
 * the server keeps such a file, and an edit to the value changes the
 * documents as an edit to a "const" does. What a file contributes of its
 * functions is the ones named as a function of PHP the reading follows:
 * another function added beside one changes nothing.
 *
 * PHP 8.4 (php -r): "namespace App; define('APP_RE', '/x/');" leaves
 * \APP_RE "/x/".
 */
final class PatternDeclarationsNamespacedDefineTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = Path::fromHost(sys_get_temp_dir()).'/regex-parser-lsp-namespaced-define-'.bin2hex(random_bytes(6));
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
    public function test_a_file_declaring_only_a_define_of_a_namespace_is_kept(): void
    {
        $this->write('src/constants.php', self::constants("'/x/'"));
        $declarations = new PatternDeclarations();
        $declarations->scanWorkspace($this->root, ['src'], []);

        $this->assertTrue($declarations->constants()->declaresGlobal('APP_RE'));
        $this->assertSame('/x/', $declarations->constants()->globalConstant('APP_RE')?->value);
    }

    /**
     * @return iterable<string, array{before: string, after: string, changed: bool}>
     */
    public static function provideEditsOfADefineOfANamespace(): iterable
    {
        yield 'another value' => ['before' => "'/x/'", 'after' => "'/y/'", 'changed' => true];
        yield 'the flags' => ['before' => "'/x/'", 'after' => "'/x/i'", 'changed' => true];
        yield 'one string made a ternary' => ['before' => "'/x/'", 'after' => "\\PHP_INT_SIZE === 8 ? '/x/' : '/y/'", 'changed' => true];
        yield 'a value the file does not prove' => ['before' => "'/x/'", 'after' => "strtoupper('/x/')", 'changed' => true];
        yield 'a value the file proves again' => ['before' => "strtoupper('/x/')", 'after' => "'/x/'", 'changed' => true];
        yield 'an empty array made a number' => ['before' => '[]', 'after' => '1.5', 'changed' => true];
        yield 'the same value' => ['before' => "'/x/'", 'after' => "'/x/'", 'changed' => false];
        yield 'the same value, moved along the line' => ['before' => "'/x/'", 'after' => "    '/x/'", 'changed' => false];
        yield 'the same value the file does not prove' => ['before' => "strtoupper('/x/')", 'after' => "strtoupper('/x/')", 'changed' => false];
    }

    #[Test]
    #[DataProvider('provideEditsOfADefineOfANamespace')]
    public function test_an_edit_changes_the_documents_when_it_changes_a_define_of_a_namespace(string $before, string $after, bool $changed): void
    {
        $constants = $this->write('src/constants.php', self::constants($before));
        $declarations = new PatternDeclarations();
        $declarations->scanWorkspace($this->root, ['src'], []);

        $this->assertSame($changed, $declarations->readFile($constants, self::constants($after)));
    }

    /**
     * The function of the namespace named define, declared in another
     * file, is the one the call reaches: the constant is no longer
     * declared, and the documents change.
     */
    #[Test]
    public function test_a_function_named_define_declared_in_the_namespace_takes_the_constant_away(): void
    {
        $this->write('src/constants.php', self::constants("'/x/'"));
        $functions = $this->write('src/functions.php', "<?php\nnamespace App;\n");
        $declarations = new PatternDeclarations();
        $declarations->scanWorkspace($this->root, ['src'], []);

        $this->assertTrue($declarations->constants()->declaresGlobal('APP_RE'));
        $this->assertTrue($declarations->readFile($functions, self::functions('define')), 'Declared.');
        $this->assertFalse($declarations->constants()->declaresGlobal('APP_RE'));
        $this->assertTrue($declarations->readFile($functions, "<?php\nnamespace App;\n"), 'Removed.');
        $this->assertSame('/x/', $declarations->constants()->globalConstant('APP_RE')?->value);
    }

    /**
     * A function PHP has none of the name of is reached by no call the
     * reading follows: declared beside one that is, it is not kept, and
     * changes no document.
     */
    #[Test]
    public function test_a_function_the_reading_does_not_follow_changes_nothing_beside_one_it_follows(): void
    {
        $functions = $this->write('src/functions.php', self::functions('sprintf'));
        $declarations = new PatternDeclarations();
        $declarations->scanWorkspace($this->root, ['src'], []);

        $this->assertFalse($declarations->readFile($functions, self::functions('sprintf').self::declaration('helper')), 'Another function, beside it.');
        $this->assertTrue($declarations->constants()->declaresFunction('app\\sprintf'));
        $this->assertFalse($declarations->constants()->declaresFunction('app\\helper'));
        $this->assertTrue($declarations->readFile($functions, self::functions('sprintf').self::declaration('implode')), 'One the reading follows, beside it.');
        $this->assertTrue($declarations->constants()->declaresFunction('app\\implode'));
    }

    private static function constants(string $value): string
    {
        return "<?php\nnamespace App;\ndefine('APP_RE', ".$value.");\n";
    }

    private static function functions(string $name): string
    {
        return "<?php\nnamespace App;\n".self::declaration($name);
    }

    private static function declaration(string $name): string
    {
        return 'function '.$name."(string \$text): string\n{\n    return \$text;\n}\n";
    }

    private function write(string $relative, string $content): string
    {
        $path = $this->root.'/'.$relative;
        file_put_contents($path, $content);

        return $path;
    }
}
