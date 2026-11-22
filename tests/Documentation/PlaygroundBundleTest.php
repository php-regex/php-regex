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
 * @phpstan-type Pinned array{release: string, pcre2: array{tag: string, commit: string}, patches: list<string>, emscripten: array{image: string, digest: string}, module: string, sha256: string, bytes: int, exports: list<string>, imports: list<string>}
 * @phpstan-type Bundle array{manifest: array<string, mixed>, files: array<string, string>, modules?: mixed}
 */
#[Group('documentation')]
final class PlaygroundBundleTest extends TestCase
{
    /**
     * The PCRE2 releases the page's engines bundle, one trace module each:
     * 8.0 10.35, 8.1 10.39, 8.2 10.40, 8.3 10.42, 8.4, 8.5 and 8.6 10.44.
     */
    private const RELEASES = ['10.35', '10.39', '10.40', '10.42', '10.44'];
    private const MANIFEST = PlaygroundDriver::ROOT.'/wasm/pcre2-trace/manifest.json';
    private const MODULES = PlaygroundDriver::ROOT.'/docs/assets/playground';

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
     * The trace modules sit beside the bundle, one file per PCRE2 release
     * (pcre2-<release>.wasm), fetched only when the Step through tab traces
     * on an engine of that release. The bundle names each one in its
     * manifest, with the sha256 and the size the worker checks the fetched
     * bytes against, and carries no module bytes itself.
     */
    #[Test]
    public function test_the_bundle_names_a_trace_module_per_release_and_carries_none(): void
    {
        $bundle = self::rawBundle();

        $this->assertArrayNotHasKey('modules', $bundle, 'The module bytes are files of their own, never base64 in the bundle.');
        $manifest = $bundle['manifest']['modules'] ?? null;
        $this->assertIsArray($manifest);
        $this->assertSame(array_map(static fn (string $release): string => 'pcre2-'.$release, self::RELEASES), array_keys($manifest));
        foreach (self::RELEASES as $release) {
            $entry = $manifest['pcre2-'.$release];
            $this->assertIsArray($entry);
            $this->assertSame(['release', 'sha256', 'bytes'], array_keys($entry));
            $this->assertSame($release, $entry['release']);
            $this->assertIsString($entry['sha256']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $entry['sha256']);
            $this->assertIsInt($entry['bytes']);
            $this->assertGreaterThan(100000, $entry['bytes']);

            $file = self::MODULES.'/pcre2-'.$release.'.wasm';
            $this->assertFileExists($file, 'The bundle names pcre2-'.$release.' but no file serves it: regenerate the bundle.');
            $bytes = (string) file_get_contents($file);
            $this->assertSame($entry['bytes'], \strlen($bytes));
            $this->assertSame($entry['sha256'], hash('sha256', $bytes));
            $this->assertStringStartsWith("\0asm", $bytes, 'The bytes are a WebAssembly module.');
        }
        $served = array_map(basename(...), glob(self::MODULES.'/*.wasm') ?: []);
        sort($served);
        $this->assertSame(array_map(static fn (string $release): string => 'pcre2-'.$release.'.wasm', self::RELEASES), $served, 'Only the modules the bundle names are served.');
    }

    /**
     * Each module's checksum is pinned in the tree, where CI rebuilds the
     * module and compares: the page serves the bytes that pin names, built
     * from the release's tag with php-src's patches to its bundled copy.
     */
    #[Test]
    public function test_the_served_modules_are_the_ones_the_tree_pins(): void
    {
        $this->assertFileExists(self::MANIFEST);
        /** @var array<string, Pinned> $pinned */
        $pinned = json_decode((string) file_get_contents(self::MANIFEST), true, 512, \JSON_THROW_ON_ERROR);
        /** @var array<string, array{release: string, sha256: string, bytes: int}> $manifest */
        $manifest = self::rawBundle()['manifest']['modules'] ?? [];

        $this->assertSame(self::RELEASES, array_keys($pinned));
        foreach (self::RELEASES as $release) {
            $entry = $pinned[$release];
            $this->assertSame(['release', 'pcre2', 'patches', 'emscripten', 'module', 'sha256', 'bytes', 'exports', 'imports'], array_keys($entry));
            $this->assertSame($release, $entry['release']);
            $this->assertSame('pcre2-'.$release, $entry['pcre2']['tag']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $entry['pcre2']['commit']);
            $this->assertSame('pcre2-'.$release.'.wasm', $entry['module']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $entry['sha256']);
            $this->assertSame(['env.emscripten_notify_memory_growth'], $entry['imports']);
            foreach ($entry['patches'] as $patch) {
                $this->assertFileExists(\dirname(self::MANIFEST).'/patches/'.$patch.'.patch');
            }
            $this->assertArrayHasKey('pcre2-'.$release, $manifest);
            $this->assertSame($entry['sha256'], $manifest['pcre2-'.$release]['sha256'], 'The page serves other bytes than the tree pins for '.$release.': rebuild the module or regenerate the bundle.');
            $this->assertSame($entry['bytes'], $manifest['pcre2-'.$release]['bytes']);
        }
        // php-src carries the GH-11374 backport in its bundled 10.39 (8.1.26
        // and later) and 10.42 (8.3.0 and later), not in 8.2.11's 10.40.
        $this->assertSame(['10.39', '10.42'], array_values(array_filter(self::RELEASES, static fn (string $release): bool => \in_array('gh-11374', $pinned[$release]['patches'], true))));
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
