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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The functions and static methods of the workspace that declare a pattern
 * parameter: read from the PHP files regex.json's "paths" and "exclude"
 * select, and from the open documents, which stand for their file while
 * they are open.
 */
final class PatternDeclarationsTest extends TestCase
{
    private const GREP = "<?php\nnamespace App;\nuse PHPRegex\\Parser\\Attribute\\RegexPattern;\nfunction grep(string \$subject, #[RegexPattern] string \$regex) {}\n";

    private const PHPSTORM = "<?php\nnamespace App;\nfunction look(#[\\JetBrains\\PhpStorm\\Language('RegExp')] string \$regex) {}\n";

    private string $root;

    protected function setUp(): void
    {
        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/regex-parser-lsp-declarations-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        self::remove($this->root);
    }

    #[Test]
    public function test_the_workspace_files_are_read_for_declarations(): void
    {
        $this->write('src/Grep.php', self::GREP);
        $this->write('src/Deep/Look.php', self::PHPSTORM);

        $declarations = new PatternDeclarations();

        $this->assertTrue($declarations->scanWorkspace($this->root, ['.'], ['vendor']));
        $this->assertSame(1, $declarations->functionArgument('App\grep'));
        $this->assertSame(1, $declarations->functionArgument('\APP\GREP'), 'PHP names are case-insensitive.');
        $this->assertSame(0, $declarations->functionArgument('App\look'));
        $this->assertNull($declarations->functionArgument('App\other'));
    }

    #[Test]
    public function test_the_excluded_directories_templates_and_other_paths_are_not_read(): void
    {
        $this->write('vendor/lib/Grep.php', self::GREP);
        $this->write('src/test/resources/Grep.php', self::GREP);
        $this->write('src/view.blade.php', self::GREP);
        $this->write('src/grep.txt', self::GREP);
        $this->write('lib/Grep.php', self::GREP);

        $declarations = new PatternDeclarations();
        $declarations->scanWorkspace($this->root, ['src', $this->root.'/missing'], ['vendor', '/src/test/resources/']);

        $this->assertNull($declarations->functionArgument('App\grep'));
        $this->assertTrue($declarations->isEmpty());
    }

    #[Test]
    public function test_a_path_may_name_a_file(): void
    {
        $this->write('lib/Grep.php', self::GREP);

        $declarations = new PatternDeclarations();
        $declarations->scanWorkspace($this->root, ['lib/Grep.php'], []);

        $this->assertSame(1, $declarations->functionArgument('App\grep'));
    }

    /**
     * A file of the workspace too large to tokenize in the memory left is
     * passed over silently, and the scan goes on with the others.
     */
    #[Test]
    public function test_a_file_too_large_for_the_memory_left_is_passed_over(): void
    {
        $this->write('src/Grep.php', self::GREP);
        $this->write('src/Huge.php', str_replace('grep', 'huge', self::GREP).str_repeat("// padding\n", 100_000));
        $declarations = new PatternDeclarations();
        $limit = ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) + 32 * 1024 * 1024));

        try {
            $complete = $declarations->scanWorkspace($this->root, ['src'], []);
        } finally {
            ini_set('memory_limit', \is_string($limit) ? $limit : '-1');
        }

        $this->assertTrue($complete);
        $this->assertSame(1, $declarations->functionArgument('App\grep'));
        $this->assertNull($declarations->functionArgument('App\huge'));
    }

    /**
     * The scan stops at the file limit, and says so.
     */
    #[Test]
    public function test_the_scan_stops_at_the_file_limit(): void
    {
        $this->write('src/A.php', '<?php');
        $this->write('src/B.php', '<?php');

        $this->assertFalse((new PatternDeclarations(1))->scanWorkspace($this->root, ['src'], []));
        $this->assertTrue((new PatternDeclarations(2))->scanWorkspace($this->root, ['src'], []));
    }

    #[Test]
    public function test_a_file_of_the_workspace_is_read_again_or_forgotten(): void
    {
        $declarations = new PatternDeclarations();
        $declarations->scanWorkspace($this->root, ['src'], ['vendor']);

        $path = $this->write('src/Grep.php', self::GREP);
        $this->assertTrue($declarations->readFile($path));
        $this->assertSame(1, $declarations->functionArgument('App\grep'));
        $this->assertFalse($declarations->readFile($path), 'Nothing changed.');

        $this->assertTrue($declarations->readFile($path, "<?php\nnamespace App;\nfunction grep(\$regex) {}\n"), 'The text given is read, not the file.');
        $this->assertNull($declarations->functionArgument('App\grep'));

        $this->assertTrue($declarations->readFile($path));
        $this->assertTrue($declarations->forgetFile($path));
        $this->assertNull($declarations->functionArgument('App\grep'));
        $this->assertFalse($declarations->forgetFile($path));
    }

    #[Test]
    public function test_a_file_outside_the_workspace_is_not_read(): void
    {
        $declarations = new PatternDeclarations();
        $this->assertFalse($declarations->readFile($this->write('src/Grep.php', self::GREP)), 'No workspace was read.');
        unlink($this->root.'/src/Grep.php');

        $declarations->scanWorkspace($this->root, ['src'], ['vendor']);

        $this->assertFalse($declarations->readFile($this->write('vendor/Grep.php', self::GREP)));
        $this->assertFalse($declarations->readFile($this->write('lib/Grep.php', self::GREP)));
        $this->assertFalse($declarations->readFile($this->write('src/grep.blade.php', self::GREP)));
        $this->assertNull($declarations->functionArgument('App\grep'));
    }

    /**
     * An open document stands for its file: its text, saved or not, holds
     * the declarations, until it is closed.
     */
    #[Test]
    public function test_an_open_document_stands_for_its_file(): void
    {
        $path = $this->write('src/Grep.php', self::GREP);
        $declarations = new PatternDeclarations();
        $declarations->scanWorkspace($this->root, ['src'], []);
        $uri = 'file://'.$path;

        $this->assertTrue($declarations->readDocument($uri, "<?php\nnamespace App;\nfunction grep(\$regex) {}\n"));
        $this->assertNull($declarations->functionArgument('App\grep'));

        $this->assertTrue($declarations->closeDocument($uri));
        $this->assertSame(1, $declarations->functionArgument('App\grep'));
        $this->assertFalse($declarations->closeDocument($uri));
    }

    #[Test]
    public function test_an_open_document_outside_the_workspace_counts(): void
    {
        $declarations = new PatternDeclarations();

        $this->assertTrue($declarations->readDocument('untitled:Untitled-1', self::GREP));
        $this->assertFalse($declarations->readDocument('untitled:Untitled-1', self::GREP), 'Nothing changed.');
        $this->assertFalse($declarations->readDocument('untitled:Untitled-2', "<?php\nfunction plain(\$text) {}\n"));
        $this->assertSame(1, $declarations->functionArgument('App\grep'));
    }

    #[Test]
    public function test_a_static_method_is_declared_with_its_class(): void
    {
        $declarations = new PatternDeclarations();
        $declarations->readDocument('file:///workspace/Str.php', "<?php\nnamespace App;\nuse PHPRegex\\Parser\\Attribute\\RegexPattern;\nclass Str { public static function matches(string \$s, #[RegexPattern] string \$regex) {} }\n");

        $this->assertSame(1, $declarations->methodArgument('App\Str', 'matches'));
        $this->assertSame(1, $declarations->methodArgument('\app\str', 'MATCHES'));
        $this->assertNull($declarations->methodArgument('App\Other', 'matches'));
        $this->assertNull($declarations->functionArgument('App\Str::matches'));
    }

    #[Test]
    public function test_a_file_uri_is_read_as_a_path(): void
    {
        $this->assertSame('/work space/src/A.php', PatternDeclarations::pathOf('file:///work%20space/src/A.php'));
        $this->assertSame('C:/project/A.php', PatternDeclarations::pathOf('file:///C:/project/A.php'));
        $this->assertNull(PatternDeclarations::pathOf('untitled:Untitled-1'));
    }

    private function write(string $relative, string $content): string
    {
        $path = $this->root.'/'.$relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o700, true);
        }
        file_put_contents($path, $content);

        return $path;
    }

    private static function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    self::remove($path.'/'.$entry);
                }
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }
}
