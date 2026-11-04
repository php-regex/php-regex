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

namespace PHPRegex\Tests\Documentation;

use PHPRegex\Parser\Printer\JsonDumper;
use PHPRegex\Tests\Support\PlaygroundDriver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The bundle is what the browser engine loads: the core library and the
 * driver, stripped of comments, as one JSON file. It is generated, so the
 * tests hold its shape and its self-sufficiency, not its freshness against
 * src/: the home runs the snapshot its manifest names, by design.
 *
 * @phpstan-type Module array{release: string, sha256: string, bytes: int, base64: string}
 * @phpstan-type Bundle array{manifest: array<string, mixed>, files: array<string, string>, modules?: array<string, Module>}
 */
#[Group('documentation')]
final class PlaygroundBundleTest extends TestCase
{
    /**
     * The PCRE2 release the page's 8.4 and 8.5 engines bundle: the one trace
     * module shipped at launch, named by it.
     */
    private const RELEASE = '10.44';
    private const MODULE = 'pcre2-'.self::RELEASE;
    private const MANIFEST = PlaygroundDriver::ROOT.'/wasm/pcre2-trace/manifest.json';

    #[Test]
    public function test_the_manifest_is_complete(): void
    {
        $manifest = PlaygroundDriver::bundle()['manifest'];

        $this->assertIsString($manifest['version']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[A-Z0-9]+)?$/', $manifest['version']);
        $this->assertIsString($manifest['commit']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{7,40}$/', $manifest['commit']);
        $this->assertIsString($manifest['built']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $manifest['built']);
        $this->assertGreaterThanOrEqual(500, $manifest['files']);
        $this->assertIsInt($manifest['bytes']);
        $this->assertSame(3, $manifest['protocol']);
        $this->assertSame(JsonDumper::VERSION, $manifest['jsonDumper']);
        $this->assertSame(\count(PlaygroundDriver::bundle()['files']), $manifest['files']);

        $keys = array_keys($manifest);
        sort($keys);
        $this->assertSame(['built', 'bytes', 'commit', 'files', 'jsonDumper', 'modules', 'protocol', 'version'], $keys);
    }

    /**
     * The trace module rides in the bundle the way the library's sources
     * do: base64 under a modules key, named by its PCRE2 release, with the
     * sha256 and the size of the bytes it decodes to. The manifest repeats
     * the release, the sha256 and the size, without the bytes.
     */
    #[Test]
    public function test_the_bundle_embeds_the_trace_module(): void
    {
        $bundle = self::rawBundle();

        $this->assertArrayHasKey('modules', $bundle);
        $modules = $bundle['modules'] ?? [];
        $this->assertSame([self::MODULE], array_keys($modules));
        $module = $modules[self::MODULE];
        $this->assertSame(['release', 'sha256', 'bytes', 'base64'], array_keys($module));
        $this->assertSame(self::RELEASE, $module['release']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $module['sha256']);
        $this->assertIsInt($module['bytes']);
        $this->assertGreaterThan(100000, $module['bytes']);

        $bytes = base64_decode($module['base64'], true);
        $this->assertIsString($bytes, 'The module is strict base64.');
        $this->assertSame($module['bytes'], \strlen($bytes));
        $this->assertSame($module['sha256'], hash('sha256', $bytes));
        $this->assertStringStartsWith("\0asm", $bytes, 'The bytes are a WebAssembly module.');

        $manifest = $bundle['manifest']['modules'] ?? null;
        $this->assertSame([self::MODULE => ['release' => $module['release'], 'sha256' => $module['sha256'], 'bytes' => $module['bytes']]], $manifest);
    }

    /**
     * The module's checksum is pinned in the tree, where CI rebuilds the
     * module and compares: the bundle carries the bytes that pin names.
     */
    #[Test]
    public function test_the_embedded_module_is_the_one_the_tree_pins(): void
    {
        $this->assertFileExists(self::MANIFEST);
        /** @var array<string, mixed> $pinned */
        $pinned = json_decode((string) file_get_contents(self::MANIFEST), true, 512, \JSON_THROW_ON_ERROR);
        $module = self::rawBundle()['modules'][self::MODULE] ?? null;

        $this->assertIsArray($module, 'The bundle embeds no '.self::MODULE.' module.');
        $this->assertSame(self::RELEASE, $pinned['release']);
        $this->assertSame(self::MODULE.'.wasm', $pinned['module']);
        $this->assertIsString($pinned['sha256']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $pinned['sha256']);
        $this->assertSame($pinned['sha256'], $module['sha256'], 'The bundle embeds other bytes than the tree pins: rebuild the module or regenerate the bundle.');
        $this->assertSame($pinned['bytes'], $module['bytes']);
    }

    #[Test]
    public function test_the_bundle_holds_the_core_the_driver_and_nothing_else(): void
    {
        $files = PlaygroundDriver::bundle()['files'];

        $this->assertArrayHasKey('driver.php', $files);
        foreach (array_keys($files) as $path) {
            if ('driver.php' === $path) {
                continue;
            }
            $this->assertStringStartsWith('src/', $path);
            $this->assertStringEndsWith('.php', $path);
            foreach (['src/Cli/', 'src/LanguageServer/', 'src/Laravel/', 'src/Symfony/', 'src/PHPStan/', 'src/Psalm/', 'src/Rector/', 'src/PHPUnit/'] as $outside) {
                $this->assertStringStartsNotWith($outside, $path);
            }
            foreach (['/Tests/', '/Resources/', '/bin/'] as $outside) {
                $this->assertStringNotContainsString($outside, $path);
            }
            $this->assertFileExists(PlaygroundDriver::ROOT.'/'.$path, $path.' is in the bundle but no longer in the tree: regenerate the bundle.');
        }
    }

    #[Test]
    public function test_the_bundled_driver_is_the_tree_driver(): void
    {
        $this->assertSame(
            php_strip_whitespace(PlaygroundDriver::TREE_DRIVER),
            PlaygroundDriver::bundle()['files']['driver.php'],
            'The driver changed: regenerate the bundle so the page and the engine run the same one.',
        );
    }

    #[Test]
    public function test_the_bundle_is_self_sufficient(): void
    {
        $bundle = PlaygroundDriver::extractBundle();
        $default = self::defaultInput();

        $run = PlaygroundDriver::run($bundle['driver'], $bundle['root'], ['op' => 'analyze', 'regex' => $default['regex'], 'subject' => $default['subject']]);
        $this->assertSame('', $run['stderr'], 'A stripped file missing a dependency would complain here.');
        $payload = PlaygroundDriver::payload($run);
        $this->assertArrayNotHasKey('fatal', $payload);
        $this->assertSame([], $payload['errors']);

        $lint = PlaygroundDriver::payload(PlaygroundDriver::run($bundle['driver'], $bundle['root'], ['op' => 'lint', 'regex' => $default['regex']]));
        $this->assertIsArray($lint['lint']);
    }

    #[Test]
    public function test_the_bundle_is_stripped_and_bounded(): void
    {
        $bundle = PlaygroundDriver::bundle();

        foreach ($bundle['files'] as $path => $source) {
            $this->assertStringNotContainsString('/**', $source, $path.' still carries a docblock.');
            $this->assertStringStartsWith('<?php', $source, $path);
        }
        $this->assertLessThan(3 * 1024 * 1024, filesize(PlaygroundDriver::BUNDLE));
        $this->assertSame(array_sum(array_map(strlen(...), $bundle['files'])), $bundle['manifest']['bytes']);
    }

    /**
     * The bundle as written, every top-level key included.
     *
     * @return Bundle
     */
    private static function rawBundle(): array
    {
        /** @var Bundle $bundle */
        $bundle = json_decode((string) file_get_contents(PlaygroundDriver::BUNDLE), true, 512, \JSON_THROW_ON_ERROR);

        return $bundle;
    }

    /**
     * @return array{regex: string, subject: string}
     */
    private static function defaultInput(): array
    {
        /** @var array{default: array{regex: string, subject: string}} $data */
        $data = json_decode((string) file_get_contents(PlaygroundDriver::DEFAULT_DATA), true, 512, \JSON_THROW_ON_ERROR);

        return ['regex' => $data['default']['regex'], 'subject' => $data['default']['subject']];
    }
}
