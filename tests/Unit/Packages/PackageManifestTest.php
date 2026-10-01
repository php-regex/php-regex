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
                $this->assertSame('^2.0', $constraint, $package);
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
        // PHPStan ships nikic/php-parser inside its phar.
        if (\in_array('phpstan/phpstan', $declared, true)) {
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
    public function test_no_new_use_of_another_package_internals(): void
    {
        // The uses 2.0 ships with: these classes keep their signatures for all
        // of 2.x. A new entry here is a new cross-package dependency on code
        // that promises nothing; make the class public instead, or keep it.
        $allowed = [
            'Automata' => ['Parser\Internal\StaticCaches'],
            'Cli' => ['Linter\Internal\RedosVerdict', 'Parser\Internal\Ascii', 'Parser\Internal\DisplayEscaper', 'Parser\Internal\PatternParser', 'Redos\Internal\InputGenerator'],
            'Explain' => ['Parser\Internal\Ascii', 'Parser\Internal\DisplayEscaper'],
            'Generator' => ['Parser\Internal\Ascii', 'Parser\Internal\StaticCaches'],
            'Linter' => ['Parser\Internal\Ascii', 'Parser\Internal\DisplayEscaper', 'Parser\Internal\PatternParser'],
            'Optimizer' => ['Parser\Internal\PatternParser'],
            'Redos' => ['Parser\Internal\PatternParser', 'Parser\Internal\StaticCaches'],
            'Symfony' => ['Parser\Internal\DisplayEscaper'],
            'Toolkit' => ['Parser\Internal\PatternParser'],
        ];

        $found = [];
        foreach (array_keys(self::PACKAGES) as $directory) {
            $uses = [];
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/src/'.$directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                    preg_match_all('~PHPRegex\\\\((\w+)\\\\Internal\\\\\w+)~', (string) file_get_contents($file->getPathname()), $matches, \PREG_SET_ORDER);
                    foreach ($matches as $match) {
                        if ($match[2] !== $directory) {
                            $uses[] = $match[1];
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
