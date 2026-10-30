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

namespace PHPRegex\Tests\Functional\Cli;

use PHPRegex\Tests\TestUtils\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The entry points find the project's autoloader wherever Composer installs
 * them: from the monorepo itself, from a split package, and from the
 * monorepo installed as a dependency (php-regex/php-regex), where the script
 * sits five levels below vendor/.
 */
final class EntryPointAutoloadTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/php-regex-entry-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/vendor', 0o777, true);
        // The project's autoloader: the repository's own, through one require.
        file_put_contents($this->root.'/vendor/autoload.php', '<?php return require '.var_export(\dirname(__DIR__, 3).'/vendor/autoload.php', true).';');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->root);
    }

    #[Test]
    #[DataProvider('provideInstalledEntryPoints')]
    public function test_an_installed_entry_point_finds_the_project_autoloader(string $source, string $installedAt, string $expected): void
    {
        $script = $this->root.'/vendor/'.$installedAt;
        mkdir(\dirname($script), 0o777, true);
        copy(\dirname(__DIR__, 3).'/'.$source, $script);

        $output = [];
        exec(escapeshellarg(\PHP_BINARY).' '.escapeshellarg($script).' --version 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertStringContainsString($expected, implode("\n", $output));
    }

    /**
     * A path repository installs the package as a symlink, and PHP resolves
     * it: the script then sits in the package's own checkout, where an
     * autoloader other than the project's may be found. Composer's proxy
     * names the project's one, and that one wins.
     */
    #[Test]
    #[DataProvider('provideEntryPoints')]
    public function test_the_entry_point_loads_the_autoloader_composer_names(string $source): void
    {
        $script = $this->root.'/checkout/'.$source;
        mkdir(\dirname($script), 0o777, true);
        copy(\dirname(__DIR__, 3).'/'.$source, $script);
        mkdir($this->root.'/checkout/vendor');
        file_put_contents($this->root.'/checkout/vendor/autoload.php', '<?php fwrite(STDERR, "the checkout\'s autoloader"); exit(3);');

        // What Composer writes in vendor/bin for a PHP binary.
        $proxy = $this->root.'/vendor/bin/'.basename($source);
        mkdir(\dirname($proxy));
        file_put_contents($proxy, '<?php $GLOBALS[\'_composer_autoload_path\'] = __DIR__.\'/../autoload.php\'; include '.var_export($script, true).';');

        $output = [];
        exec(escapeshellarg(\PHP_BINARY).' '.escapeshellarg($proxy).' --version 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertStringNotContainsString("the checkout's autoloader", implode("\n", $output));
    }

    #[Test]
    #[DataProvider('provideEntryPoints')]
    public function test_an_entry_point_without_autoloader_says_how_to_install(string $source): void
    {
        $script = $this->root.'/a/b/c/d/e/bin/'.basename($source);
        mkdir(\dirname($script), 0o777, true);
        copy(\dirname(__DIR__, 3).'/'.$source, $script);

        // Started without a shell: the standard error alone is read.
        $process = proc_open([\PHP_BINARY, $script, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        $this->assertSame(1, $code, $stderr);
        $this->assertStringContainsString('composer install', $stderr);
        $this->assertStringNotContainsString('Stack trace', $stderr);
    }

    /**
     * @return iterable<string, array{source: string}>
     */
    public static function provideEntryPoints(): iterable
    {
        yield 'regex' => ['source' => 'src/Cli/bin/regex'];
        yield 'regex-lsp' => ['source' => 'src/LanguageServer/bin/regex-lsp'];
    }

    /**
     * @return iterable<string, array{source: string, installedAt: string, expected: string}>
     */
    public static function provideInstalledEntryPoints(): iterable
    {
        yield 'regex, monorepo as a dependency' => ['source' => 'src/Cli/bin/regex', 'installedAt' => 'php-regex/php-regex/src/Cli/bin/regex', 'expected' => 'PHPRegex'];
        yield 'regex, split package' => ['source' => 'src/Cli/bin/regex', 'installedAt' => 'php-regex/regex-cli/bin/regex', 'expected' => 'PHPRegex'];
        yield 'regex-lsp, monorepo as a dependency' => ['source' => 'src/LanguageServer/bin/regex-lsp', 'installedAt' => 'php-regex/php-regex/src/LanguageServer/bin/regex-lsp', 'expected' => 'php-regex-lsp'];
        yield 'regex-lsp, split package' => ['source' => 'src/LanguageServer/bin/regex-lsp', 'installedAt' => 'php-regex/regex-language-server/bin/regex-lsp', 'expected' => 'php-regex-lsp'];
    }
}
