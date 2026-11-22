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

use PHPRegex\Tests\Support\PlaygroundDriver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * php-wasm 0.2.0 publishes PHP 8.0 to 8.5.2 and nothing newer, so the
 * playground's PHP 8.6 engine is built in the tree: php-wasm's v0.2.0
 * sources with 8.6.0 added (wasm/php-wasm/build.sh and its patches),
 * served by the site beside a manifest that pins every file by size and
 * sha256. These tests hold the served files to the manifest, the manifest
 * to the build script and the options table, and the worker to the split
 * it promises: 8.6 from the site, every other version from jsDelivr.
 *
 * @phpstan-type Manifest array{php: array{version: string, tag: string, commit: string, pcre2: string}, phpWasm: array{repository: string, tag: string, commit: string}, patches: array<string, string>, emscripten: array{image: string, platform: string, digest: string}, sourceDateEpoch: int, files: array<string, array{bytes: int, sha256: string}>}
 */
#[Group('documentation')]
final class PlaygroundPhpEngineTest extends TestCase
{
    private const RECIPE = PlaygroundDriver::ROOT.'/wasm/php-wasm';
    private const MANIFEST = self::RECIPE.'/manifest.json';
    private const ASSETS = PlaygroundDriver::ROOT.'/docs/assets';
    private const SERVED = ['php-wasm', 'php-wasm-mbstring'];
    private const WORKER = self::ASSETS.'/js/run-worker.js';
    private const OPTIONS_TABLE = self::ASSETS.'/js/php-pcre-options.js';

    #[Test]
    public function test_the_manifest_names_the_sources_and_the_toolchain(): void
    {
        $manifest = self::manifest();

        $this->assertSame(['php', 'phpWasm', 'patches', 'emscripten', 'sourceDateEpoch', 'files'], array_keys($manifest));
        $this->assertSame(['version' => '8.6.0', 'tag' => 'php-8.6.0'], array_intersect_key($manifest['php'], ['version' => true, 'tag' => true]));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $manifest['php']['commit']);
        $this->assertSame(['repository' => 'https://github.com/seanmorris/php-wasm', 'tag' => 'v0.2.0'], array_intersect_key($manifest['phpWasm'], ['repository' => true, 'tag' => true]));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $manifest['phpWasm']['commit']);
        $this->assertSame('emscripten/emsdk:6.0.6', $manifest['emscripten']['image'], 'php-wasm 0.2.0 builds with Emscripten 6.0.6.');
        $this->assertSame('linux/amd64', $manifest['emscripten']['platform']);
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $manifest['emscripten']['digest']);
        $this->assertGreaterThan(0, $manifest['sourceDateEpoch']);

        $this->assertSame(['php8.6.patch', 'php-wasm-8.6.patch', 'vrzno-8.6.patch'], array_keys($manifest['patches']));
        foreach ($manifest['patches'] as $patch => $sha256) {
            $this->assertFileExists(self::RECIPE.'/patches/'.$patch);
            $this->assertSame($sha256, hash_file('sha256', self::RECIPE.'/patches/'.$patch), $patch.' is not the patch the manifest pins: rebuild the engine.');
        }
    }

    /**
     * The engine reads PCRE2 options from the table's row for its exact
     * version, and the row was read from php-src at a commit: the engine
     * is built from that same commit, with the PCRE2 release the row names.
     */
    #[Test]
    public function test_the_engine_is_built_from_the_commit_the_options_row_reads(): void
    {
        $manifest = self::manifest();
        $table = self::optionsTable();

        $this->assertArrayHasKey($manifest['php']['version'], $table);
        $row = $table[$manifest['php']['version']];
        $this->assertSame($row['commit'], $manifest['php']['commit']);
        $this->assertSame($row['tag'], $manifest['php']['tag']);
        $this->assertSame($row['pcre2'], $manifest['php']['pcre2']);
        $this->assertFileExists(PlaygroundDriver::ROOT.'/docs/assets/playground/pcre2-'.$row['pcre2'].'.wasm', 'The step debugger serves a trace module of the engine\'s PCRE2 release.');
    }

    #[Test]
    public function test_the_build_script_pins_what_the_manifest_names(): void
    {
        $manifest = self::manifest();
        $script = (string) file_get_contents(self::RECIPE.'/build.sh');

        $this->assertStringContainsString($manifest['phpWasm']['commit'], $script);
        $this->assertStringContainsString($manifest['php']['commit'], $script);
        $this->assertStringContainsString($manifest['emscripten']['digest'], $script);
        $this->assertStringContainsString((string) $manifest['sourceDateEpoch'], $script);
    }

    /**
     * Every served file is one the manifest pins, byte for byte, and the
     * manifest pins no file the site does not serve.
     */
    #[Test]
    public function test_the_served_files_are_the_ones_the_manifest_pins(): void
    {
        $files = self::manifest()['files'];

        $served = [];
        foreach (self::SERVED as $directory) {
            foreach (glob(self::ASSETS.'/'.$directory.'/{,.}*', \GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    $served[] = $directory.'/'.basename($file);
                }
            }
        }
        sort($served);
        $pinned = array_keys($files);
        sort($pinned);
        $this->assertSame($pinned, $served, 'docs/assets serves other engine files than the manifest pins: run bin/playground-bundle.');

        foreach ($files as $path => $pin) {
            $bytes = (string) file_get_contents(self::ASSETS.'/'.$path);
            $this->assertSame($pin['bytes'], \strlen($bytes), $path);
            $this->assertSame($pin['sha256'], hash('sha256', $bytes), $path.' is not the file the manifest pins: rebuild the engine or run bin/playground-bundle.');
            if (str_ends_with($path, '.wasm') || str_ends_with($path, '.so')) {
                $this->assertStringStartsWith("\0asm", $bytes, $path.' is a WebAssembly module.');
            }
        }
    }

    /**
     * php-wasm names its module after the module's own sha1, and the
     * loader asks for that name: the two cannot drift apart.
     */
    #[Test]
    public function test_the_loader_asks_for_the_module_it_is_served_with(): void
    {
        $files = self::manifest()['files'];
        $modules = array_values(array_filter(array_keys($files), static fn (string $path): bool => 1 === preg_match('~^php-wasm/[0-9a-f]{40}\.wasm$~', $path)));

        $this->assertCount(1, $modules);
        $module = basename($modules[0]);
        $this->assertSame(substr($module, 0, 40), sha1_file(self::ASSETS.'/'.$modules[0]));
        $this->assertStringContainsString($module, (string) file_get_contents(self::ASSETS.'/php-wasm/php8.6-web.mjs'));
        $this->assertStringContainsString("case '8.6':", (string) file_get_contents(self::ASSETS.'/php-wasm/PhpWeb.mjs'));
        $this->assertStringContainsString("'8.6': mbstring8_6", (string) file_get_contents(self::ASSETS.'/php-wasm-mbstring/index.mjs'));
        $this->assertArrayHasKey('php-wasm-mbstring/php8.6-mbstring.so', $files);
        $this->assertArrayHasKey('php-wasm-mbstring/libonig.so', $files);
    }

    /**
     * php-wasm is Apache-2.0 (or GPL-2.0): its licence texts and its
     * notice travel with the files built from it.
     */
    #[Test]
    public function test_the_licence_and_the_notice_travel_with_the_engine(): void
    {
        $files = self::manifest()['files'];

        foreach (self::SERVED as $directory) {
            foreach (['LICENSE', 'LICENSE-GPL', 'NOTICE'] as $text) {
                $this->assertArrayHasKey($directory.'/'.$text, $files);
            }
        }
        $this->assertStringContainsString('Apache License', (string) file_get_contents(self::ASSETS.'/php-wasm/LICENSE'));
        $this->assertStringContainsString('Sean Morris', (string) file_get_contents(self::ASSETS.'/php-wasm/NOTICE'));
    }

    /**
     * The worker loads 8.6 from the site, same-origin, and every other
     * version from jsDelivr as before; the version selects offer 8.6.
     */
    #[Test]
    public function test_the_worker_loads_8_6_from_the_site_and_the_rest_from_jsdelivr(): void
    {
        $worker = (string) file_get_contents(self::WORKER);

        $this->assertStringContainsString("const RUNTIME = 'https://cdn.jsdelivr.net/npm/php-wasm@0.2.0/PhpWeb.mjs';", $worker);
        $this->assertStringContainsString("const MBSTRING = 'https://cdn.jsdelivr.net/npm/php-wasm-mbstring@0.2.0/index.mjs';", $worker);
        $this->assertMatchesRegularExpression("~'8\\.6': \\{\\s*runtime: new URL\\('\\.\\./php-wasm/PhpWeb\\.mjs', import\\.meta\\.url\\)\\.href,\\s*mbstring: new URL\\('\\.\\./php-wasm-mbstring/index\\.mjs', import\\.meta\\.url\\)\\.href,\\s*\\}~", $worker);

        foreach (['run.js', 'playground.js'] as $script) {
            $source = (string) file_get_contents(self::ASSETS.'/js/'.$script);
            $this->assertStringContainsString("var VERSIONS = ['8.0', '8.1', '8.2', '8.3', '8.4', '8.5', '8.6'];", $source, $script.' offers every engine.');
        }
    }

    /**
     * The served files are bytes the manifest pins: git must not rewrite
     * their line endings, and Jekyll must not drop php-wasm's _Event.mjs
     * as a private file.
     */
    #[Test]
    public function test_git_and_jekyll_leave_the_served_files_as_built(): void
    {
        $attributes = (string) file_get_contents(PlaygroundDriver::ROOT.'/.gitattributes');
        foreach (self::SERVED as $directory) {
            $this->assertStringContainsString('/docs/assets/'.$directory.'/** -text', $attributes);
        }

        $this->assertMatchesRegularExpression('/^include:\n(?:  - .+\n)*  - _Event\.mjs$/m', (string) file_get_contents(PlaygroundDriver::ROOT.'/docs/_config.yml'));
    }

    /**
     * @return Manifest
     */
    private static function manifest(): array
    {
        self::assertFileExists(self::MANIFEST, 'No engine manifest: build the engine with wasm/php-wasm/build.sh 8.6.0 --write-manifest.');
        /** @var Manifest $manifest */
        $manifest = json_decode((string) file_get_contents(self::MANIFEST), true, 512, \JSON_THROW_ON_ERROR);

        return $manifest;
    }

    /**
     * The options table as the page reads it: one JSON object behind the
     * window assignment.
     *
     * @return array<string, array{commit: string, tag: string, pcre2: string}>
     */
    private static function optionsTable(): array
    {
        $source = (string) file_get_contents(self::OPTIONS_TABLE);
        self::assertSame(1, preg_match('/^window\.PHPRegexPcreOptions = (\{.*\});\s*$/s', $source, $m));
        /** @var array<string, array{commit: string, tag: string, pcre2: string}> $table */
        $table = json_decode($m[1], true, 512, \JSON_THROW_ON_ERROR);

        return $table;
    }
}
