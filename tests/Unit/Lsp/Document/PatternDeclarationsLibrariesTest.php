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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The libraries of vendor/ declare the pattern parameters the project
 * calls: they are read once, as `regex lint` reads them, and again when a
 * file of theirs changes or Composer installs others. A declaration of the
 * project wins over a library's of the same function.
 */
final class PatternDeclarationsLibrariesTest extends TestCase
{
    private const GREP = "<?php\nnamespace App;\nuse PHPRegex\\Parser\\Attribute\\RegexPattern;\nfunction grep(string \$subject, #[RegexPattern] string \$regex) {}\n";

    private const GREP_FIRST = "<?php\nnamespace App;\nuse PHPRegex\\Parser\\Attribute\\RegexPattern;\nfunction grep(#[RegexPattern] string \$pattern, string \$subject) {}\n";

    private string $root;

    protected function setUp(): void
    {
        $this->root = Path::fromHost(sys_get_temp_dir()).'/regex-parser-lsp-libraries-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        self::remove($this->root);
    }

    #[Test]
    public function test_the_libraries_are_read_for_declarations(): void
    {
        $this->write('vendor/acme/lib/src/Grep.php', self::GREP);
        $this->write('vendor/acme/lib/src/view.blade.php', str_replace('grep', 'view', self::GREP));
        $declarations = new PatternDeclarations();

        $this->assertTrue($declarations->scanWorkspace($this->root, ['.'], ['vendor']));
        $this->assertTrue($declarations->isEmpty(), 'The workspace scan leaves vendor/ to its own.');

        $this->assertTrue($declarations->scanLibraries($this->root.'/vendor'));
        $this->assertSame(['App\grep#1$regex'], $declarations->specs());
        $this->assertSame(1, $declarations->functionArgument('App\grep'));
    }

    #[Test]
    public function test_a_declaration_of_the_project_wins_over_a_library_s(): void
    {
        $this->write('vendor/acme/lib/Grep.php', self::GREP);
        $this->write('src/Grep.php', self::GREP_FIRST);
        $declarations = new PatternDeclarations();
        $declarations->scanWorkspace($this->root, ['src'], ['vendor']);
        $declarations->scanLibraries($this->root.'/vendor');

        $this->assertSame(['App\grep#0$pattern'], $declarations->specs());

        // Without the project's, the library's counts.
        $this->assertTrue($declarations->forgetFile($this->root.'/src/Grep.php'));
        $this->assertSame(['App\grep#1$regex'], $declarations->specs());
    }

    #[Test]
    public function test_a_library_file_is_read_again_or_forgotten(): void
    {
        $declarations = new PatternDeclarations();
        $declarations->scanLibraries($this->root.'/vendor');

        $path = $this->write('vendor/acme/lib/Grep.php', self::GREP);
        $this->assertTrue($declarations->readFile($path));
        $this->assertSame(1, $declarations->functionArgument('App\grep'));
        $this->assertFalse($declarations->readFile($path), 'Nothing changed.');

        $this->assertTrue($declarations->readFile($path, "<?php\nnamespace App;\nfunction grep(\$regex) {}\n"));
        $this->assertSame([], $declarations->specs());

        $this->assertFalse($declarations->readFile($this->write('vendor/acme/lib/README.md', self::GREP)), 'A library file that is no PHP declares nothing.');

        $this->assertTrue($declarations->readFile($path));
        $this->assertTrue($declarations->forgetFile($path));
        $this->assertSame([], $declarations->specs());
    }

    /**
     * Composer rewrites vendor/composer/installed.json when it installs,
     * updates or removes a package: the libraries are read again.
     */
    #[Test]
    public function test_the_libraries_are_read_again_when_composer_installs(): void
    {
        $declarations = new PatternDeclarations();
        $declarations->scanLibraries($this->root.'/vendor');
        $this->assertTrue($declarations->isEmpty());

        $this->write('vendor/acme/lib/Grep.php', self::GREP);
        $manifest = $this->write('vendor/composer/installed.json', '{"packages": []}');
        $this->assertTrue($declarations->readFile($manifest));
        $this->assertSame(1, $declarations->functionArgument('App\grep'));

        self::remove($this->root.'/vendor');
        $this->assertTrue($declarations->forgetFile($manifest));
        $this->assertTrue($declarations->isEmpty());
    }

    /**
     * A package Composer links from a path repository is followed, and a
     * link back up ends.
     */
    #[Test]
    public function test_a_linked_package_is_read_and_a_loop_ends(): void
    {
        $this->write('packages/grep/src/Grep.php', self::GREP);
        mkdir($this->root.'/vendor/acme', 0o700, true);
        symlink($this->root.'/packages/grep', $this->root.'/vendor/acme/grep');
        symlink($this->root.'/vendor', $this->root.'/packages/grep/src/loop');

        $declarations = new PatternDeclarations();

        $this->assertTrue($declarations->scanLibraries($this->root.'/vendor'));
        $this->assertSame(['App\grep#1$regex'], $declarations->specs());
    }

    #[Test]
    public function test_the_library_scan_stops_at_the_file_limit(): void
    {
        $this->write('vendor/a/A.php', '<?php');
        $this->write('vendor/b/B.php', '<?php');

        $this->assertFalse((new PatternDeclarations(1))->scanLibraries($this->root.'/vendor'));
        $this->assertTrue((new PatternDeclarations(2))->scanLibraries($this->root.'/vendor'));
    }

    /**
     * Whether a document is a library's is known from the directory alone,
     * read or missing.
     */
    #[Test]
    public function test_a_path_in_the_libraries_directory_is_a_library_s(): void
    {
        $declarations = new PatternDeclarations();
        $this->assertFalse($declarations->isLibrary($this->root.'/vendor/acme/A.php'), 'No library was read.');

        $declarations->scanLibraries($this->root.'/vendor');

        $this->assertTrue($declarations->isLibrary($this->root.'/vendor/acme/A.php'));
        $this->assertFalse($declarations->isLibrary($this->root.'/vendors/A.php'));
        $this->assertFalse($declarations->isLibrary($this->root.'/src/vendor/A.php'));
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
        if (is_link($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
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
