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

namespace PHPRegex\Tests\Unit\Packages;

use PHPRegex\Psalm\Plugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every directory under src/ ships as its own package: it declares what its
 * code imports, siblings as hard requirements and other libraries as
 * requirements or suggestions, and the root package replaces it.
 */
final class PackageManifestTest extends TestCase
{
    private const PACKAGES = [
        'Automata' => 'regex-automata',
        'Cli' => 'regex-cli',
        'Explain' => 'regex-explain',
        'Generator' => 'regex-generator',
        'LanguageServer' => 'regex-language-server',
        'Laravel' => 'regex-laravel',
        'Linter' => 'regex-linter',
        'Optimizer' => 'regex-optimizer',
        'Parser' => 'regex-parser',
        'PHPStan' => 'regex-phpstan',
        'Psalm' => 'regex-psalm',
        'Rector' => 'regex-rector',
        'Redos' => 'regex-redos',
        'Symfony' => 'regex-symfony',
        'Toolkit' => 'regex-toolkit',
        'Transpiler' => 'regex-transpiler',
    ];

    #[Test]
    public function test_every_directory_under_src_is_a_listed_package(): void
    {
        $directories = array_map(basename(...), glob(self::root().'/src/*', \GLOB_ONLYDIR) ?: []);
        sort($directories);
        $listed = array_keys(self::PACKAGES);
        sort($listed);

        $this->assertSame($listed, $directories);
    }

    #[Test]
    #[DataProvider('providePackages')]
    public function test_a_package_names_itself_and_its_namespace(string $directory, string $name): void
    {
        $manifest = self::manifest($directory);

        $this->assertSame('php-regex/'.$name, self::dig($manifest, 'name'));
        $this->assertSame('MIT', self::dig($manifest, 'license'));
        $this->assertSame(['PHPRegex\\'.$directory.'\\' => ''], self::dig($manifest, 'autoload', 'psr-4'));
        $this->assertSame('>=8.2', self::dig($manifest, 'require', 'php'));
        $this->assertSame(['dev-2.x' => '2.x-dev'], self::dig($manifest, 'extra', 'branch-alias'));
    }

    #[Test]
    #[DataProvider('provideDirectories')]
    public function test_a_package_requires_exactly_the_siblings_it_imports(string $directory): void
    {
        $required = [];
        foreach (self::strings(self::dig(self::manifest($directory), 'require')) as $package => $constraint) {
            if (str_starts_with($package, 'php-regex/')) {
                $required[] = $package;
                $this->assertSame('self.version', $constraint, $package);
            }
        }
        sort($required);

        $imported = [];
        foreach (self::importedNamespaces($directory) as $namespace) {
            if (str_starts_with($namespace, 'PHPRegex\\')) {
                $sibling = explode('\\', $namespace)[1];
                if ($sibling !== $directory) {
                    $imported[] = 'php-regex/'.self::PACKAGES[$sibling];
                }
            }
        }
        $imported = array_values(array_unique($imported));
        sort($imported);

        $this->assertSame($imported, $required);
    }

    #[Test]
    #[DataProvider('provideDirectories')]
    public function test_a_package_declares_every_library_it_imports(string $directory): void
    {
        $manifest = self::manifest($directory);
        $declared = array_keys(self::strings(self::dig($manifest, 'require')) + self::strings(self::dig($manifest, 'suggest')));
        // PHPStan ships nikic/php-parser inside its phar, Rector ships the
        // same parser with it, and Psalm requires the parser it runs on.
        if (\in_array('phpstan/phpstan', $declared, true) || \in_array('rector/rector', $declared, true) || \in_array('vimeo/psalm', $declared, true)) {
            $declared[] = 'nikic/php-parser';
        }

        $missing = [];
        foreach (self::importedNamespaces($directory) as $namespace) {
            $package = self::packageOf($namespace);
            if (null !== $package && !\in_array($package, $declared, true)) {
                $missing[] = $namespace.' ('.$package.')';
            }
        }

        $this->assertSame([], array_values(array_unique($missing)));
    }

    #[Test]
    #[DataProvider('provideDirectories')]
    public function test_a_package_ships_its_readme_license_and_changelog(string $directory): void
    {
        $path = self::root().'/src/'.$directory;

        $this->assertFileEquals(self::root().'/LICENSE', $path.'/LICENSE');
        $this->assertFileExists($path.'/README.md');
        $this->assertFileExists($path.'/CHANGELOG.md');
        $this->assertFileExists($path.'/.gitattributes');
    }

    #[Test]
    public function test_a_package_ships_the_files_its_manifest_names(): void
    {
        $this->assertSame(['extension.neon'], self::dig(self::manifest('PHPStan'), 'extra', 'phpstan', 'includes'));
        $this->assertFileExists(self::root().'/src/PHPStan/extension.neon');
        $this->assertFileExists(self::root().'/src/PHPStan/rules.neon');
        // The linter reads regex.json, so it ships the file's schema.
        $this->assertFileExists(self::root().'/src/Linter/regex.schema.json');

        foreach (['Cli' => 'bin/regex', 'LanguageServer' => 'bin/regex-lsp'] as $directory => $script) {
            $this->assertSame([$script], self::dig(self::manifest($directory), 'bin'));
            $this->assertFileIsReadable(self::root().'/src/'.$directory.'/'.$script);
            $this->assertTrue(is_executable(self::root().'/src/'.$directory.'/'.$script), $script);
        }
    }

    #[Test]
    public function test_rector_package_is_a_rector_extension_on_rector_2(): void
    {
        $manifest = self::manifest('Rector');

        $this->assertSame('rector-extension', self::dig($manifest, 'type'));
        $this->assertSame('^2.0', self::dig($manifest, 'require', 'rector/rector'));
        // Rector bundles the parser it runs on: requiring another copy could
        // only conflict with it.
        $this->assertNull(self::dig($manifest, 'require', 'nikic/php-parser'));
        $this->assertFileExists(self::root().'/src/Rector/config/sets/string-functions.php');
    }

    #[Test]
    public function test_psalm_package_is_a_psalm_plugin_on_psalm_6(): void
    {
        $manifest = self::manifest('Psalm');

        $this->assertSame('psalm-plugin', self::dig($manifest, 'type'));
        $this->assertSame('^6.19', self::dig($manifest, 'require', 'vimeo/psalm'));
        $this->assertSame(Plugin::class, self::dig($manifest, 'extra', 'psalm', 'pluginClass'));
        $this->assertFileExists(self::root().'/src/Psalm/Plugin.php');
        // Psalm requires the parser it runs on: requiring another copy could
        // only conflict with it.
        $this->assertNull(self::dig($manifest, 'require', 'nikic/php-parser'));
    }

    #[Test]
    public function test_deptrac_has_a_layer_and_a_ruleset_per_package(): void
    {
        $config = (string) file_get_contents(self::root().'/deptrac.yaml');
        preg_match_all("~- name: (\\w+)\\s+collectors:\\s+- type: classLike\\s+value: '\\^PHPRegex\\W+(\\w+)\\W+'~", $config, $layers, \PREG_SET_ORDER);

        $byDirectory = [];
        foreach ($layers as [, $layer, $directory]) {
            $byDirectory[$directory] = $layer;
        }
        ksort($byDirectory);
        $expected = array_keys(self::PACKAGES);
        sort($expected);

        $this->assertSame($expected, array_keys($byDirectory));

        $ruleset = substr($config, (int) strpos($config, "\n  ruleset:"));
        foreach ($byDirectory as $directory => $layer) {
            $this->assertMatchesRegularExpression('~^    '.$layer.': ~m', $ruleset, \sprintf('deptrac.yaml: the %s layer (src/%s) has no ruleset entry.', $layer, $directory));
        }
    }

    #[Test]
    public function test_no_new_use_of_another_package_internals(): void
    {
        // The uses 2.0 ships with. Siblings always run at the same version,
        // so these may change in any release; a new entry here is still a new
        // cross-package dependency on code that promises nothing: make the
        // class public instead, or keep it.
        // Parser\Hir is watched the same way: it stays @internal while the
        // analyses move onto the normalized form.
        $allowed = [
            // The solver reads the whole normalized form: every class of
            // it, and the translator that builds the tree.
            'Automata' => [
                'Parser\Hir\AlternationHir', 'Parser\Hir\AssertionHir', 'Parser\Hir\AssertionKind',
                'Parser\Hir\AtomicHir', 'Parser\Hir\CaptureHir', 'Parser\Hir\CharSet', 'Parser\Hir\ClassHir',
                'Parser\Hir\ConcatHir', 'Parser\Hir\ConditionalHir', 'Parser\Hir\EmptyHir', 'Parser\Hir\Greed', 'Parser\Hir\Hir',
                'Parser\Hir\HirTranslator', 'Parser\Hir\LiteralHir', 'Parser\Hir\LookHir', 'Parser\Hir\LookKind', 'Parser\Hir\OpaqueHir',
                'Parser\Hir\RepetitionHir', 'Parser\Internal\LibraryPcre', 'Parser\Internal\StartOptions',
            ],
            'Cli' => ['Linter\Internal\LintStatsCounter', 'Linter\Internal\LintSummary', 'Linter\Internal\RedosVerdict', 'Parser\Hir\CharSet', 'Parser\Hir\HirTranslator', 'Parser\Internal\Ascii', 'Parser\Internal\DisplayEscaper', 'Parser\Internal\IniFlag', 'Parser\Internal\JsonDocument', 'Parser\Internal\LibraryPcre', 'Parser\Internal\PatternParser', 'Redos\Internal\InputGenerator'],
            'Explain' => ['Parser\Internal\Ascii', 'Parser\Internal\DisplayEscaper', 'Parser\Internal\LibraryPcre'],
            'Generator' => ['Parser\Internal\Ascii', 'Parser\Internal\LibraryPcre', 'Parser\Internal\StaticCaches'],
            // The library's own regexes run under the PCRE floor in every
            // package that runs one: the floor helper is the one shared
            // internal they all use for it.
            'LanguageServer' => ['Parser\Internal\LibraryPcre'],
            'Laravel' => ['Linter\Internal\LintStatsCounter', 'Parser\Internal\JsonDocument', 'Parser\Internal\LibraryPcre'],
            'Linter' => ['Parser\Internal\Ascii', 'Parser\Internal\DisplayEscaper', 'Parser\Internal\JsonDocument', 'Parser\Internal\JsonEncodingFailure', 'Parser\Internal\LibraryPcre', 'Parser\Internal\PatternParser', 'Parser\Internal\StartOptions'],
            'Optimizer' => ['Parser\Internal\LibraryPcre', 'Parser\Internal\PatternParser'],
            'PHPStan' => ['Parser\Internal\DisplayEscaper', 'Parser\Internal\LibraryPcre'],
            // The plugin reads the key layout CaptureShape renders for
            // PHPStan, so both type systems say the same keys.
            'Psalm' => ['Parser\Internal\CaptureKey', 'Parser\Internal\CaptureLayout'],
            'Redos' => ['Parser\Hir\CharSet', 'Parser\Hir\ClassSetProvider', 'Parser\Hir\Utf8', 'Parser\Internal\IniFlag', 'Parser\Internal\PatternParser'],
            'Symfony' => ['Parser\Internal\DisplayEscaper', 'Parser\Internal\JsonDocument', 'Parser\Internal\LibraryPcre'],
            'Toolkit' => ['Parser\Internal\PatternParser'],
            'Transpiler' => ['Parser\Internal\LibraryPcre'],
        ];

        $found = [];
        foreach (array_keys(self::PACKAGES) as $directory) {
            $uses = [];
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/src/'.$directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                    // Parser\Hir counts as internal: its shape may change
                    // while the analyses migrate onto it.
                    preg_match_all('~PHPRegex\\\\(Parser\\\\Hir\\\\\w+)|PHPRegex\\\\((\w+)\\\\Internal\\\\\w+)~', (string) file_get_contents($file->getPathname()), $matches, \PREG_SET_ORDER);
                    foreach ($matches as $match) {
                        // The first alternative catches Parser\Hir uses (its
                        // package is Parser), the second the Internal ones.
                        $isHir = '' !== ($match[1] ?? '');
                        $use = $isHir ? $match[1] : ($match[2] ?? '');
                        $package = $isHir ? 'Parser' : ($match[3] ?? '');
                        if ($package !== $directory) {
                            $uses[] = $use;
                        }
                    }
                }
            }
            $uses = array_values(array_unique($uses));
            sort($uses);
            if ([] !== $uses) {
                $found[$directory] = $uses;
            }
        }
        foreach ($allowed as &$list) {
            sort($list);
        }

        $this->assertSame($allowed, $found);
    }

    #[Test]
    public function test_the_split_pushes_every_package_to_its_repository(): void
    {
        $script = (string) file_get_contents(self::root().'/bin/split');
        preg_match_all('~^\s+"src/(\w+):([\w-]+)"$~m', $script, $rows, \PREG_SET_ORDER);

        $splits = [];
        foreach ($rows as $row) {
            $splits[$row[1]] = $row[2];
        }
        ksort($splits);
        $expected = self::PACKAGES;
        ksort($expected);

        $this->assertSame($expected, $splits);
        $this->assertTrue(is_executable(self::root().'/bin/split'));
    }

    #[Test]
    public function test_the_root_package_is_the_monorepo(): void
    {
        $root = self::decode(self::root().'/composer.json');

        $this->assertSame('php-regex/php-regex', self::dig($root, 'name'));
        $this->assertSame('https://github.com/php-regex/php-regex', self::dig($root, 'homepage'));
    }

    #[Test]
    public function test_the_root_package_replaces_every_package(): void
    {
        $root = self::decode(self::root().'/composer.json');

        $expected = [];
        foreach (self::PACKAGES as $name) {
            $expected['php-regex/'.$name] = 'self.version';
        }
        ksort($expected);

        $this->assertSame($expected, self::dig($root, 'replace'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providePackages(): iterable
    {
        foreach (self::PACKAGES as $directory => $name) {
            yield $directory => [$directory, $name];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDirectories(): iterable
    {
        foreach (array_keys(self::PACKAGES) as $directory) {
            yield $directory => [$directory];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function manifest(string $directory): array
    {
        $path = self::root().'/src/'.$directory.'/composer.json';
        self::assertFileExists($path);

        return self::decode($path);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data, $path);

        $typed = [];
        foreach ($data as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    /**
     * The value at a path of keys, or null when a key is missing.
     *
     * @param array<string, mixed> $data
     */
    private static function dig(array $data, string ...$keys): mixed
    {
        $value = $data;
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private static function strings(mixed $value): array
    {
        $strings = [];
        foreach (\is_array($value) ? $value : [] as $key => $item) {
            if (\is_string($item)) {
                $strings[(string) $key] = $item;
            }
        }

        return $strings;
    }

    /**
     * The first three segments (two when there are no more) of every name the
     * package's code imports or spells in full.
     *
     * @return list<string>
     */
    private static function importedNamespaces(string $directory): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/src/'.$directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            preg_match_all('~^use (?:function |const )?\\\\?([A-Z]\w*\\\\\w+(?:\\\\\w+)?)~m', $code, $uses);
            preg_match_all('~(?<![\w\\\\])\\\\([A-Z]\w*\\\\[A-Z]\w*\\\\[A-Z]\w*)~', $code, $qualified);
            array_push($found, ...$uses[1], ...$qualified[1]);
        }

        return array_values(array_unique($found));
    }

    /**
     * The Composer package a namespace comes from, or null for PHP itself
     * and for this project's own packages.
     */
    private static function packageOf(string $namespace): ?string
    {
        $segments = explode('\\', $namespace);
        [$vendor, $second] = $segments;
        $kebab = static fn (string $name): string => strtolower((string) preg_replace('~(?<=[a-z])(?=[A-Z])~', '-', $name));

        return match ($vendor) {
            'Psr' => 'psr/'.$kebab($second),
            'PhpParser' => 'nikic/php-parser',
            'PHPStan' => 'phpstan/phpstan',
            'Rector' => 'rector/rector',
            'Psalm' => 'vimeo/psalm',
            'Symfony' => 'Component' === $second && isset($segments[2]) ? 'symfony/'.$kebab($segments[2]) : null,
            'Illuminate' => 'illuminate/'.strtolower($second),
            default => null,
        };
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
