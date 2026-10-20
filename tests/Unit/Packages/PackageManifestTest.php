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
        'PHPUnit' => 'regex-phpunit',
        'Psalm' => 'regex-psalm',
        'Rector' => 'regex-rector',
        'Redos' => 'regex-redos',
        'Symfony' => 'regex-symfony',
        'Toolkit' => 'regex-toolkit',
        'Transpiler' => 'regex-transpiler',
    ];

    /**
     * The PHP each package runs on: 8.1 for all of them, the PHPStan, Psalm
     * and Rector bridges like the tools they plug into, the Symfony and
     * Laravel bridges on Symfony 6.4 and Laravel 10. The monorepo package
     * installs every bridge, so it takes the highest floor of them.
     */
    private const PHP_FLOORS = [
        'Automata' => '>=8.1',
        'Cli' => '>=8.1',
        'Explain' => '>=8.1',
        'Generator' => '>=8.1',
        'LanguageServer' => '>=8.1',
        'Laravel' => '>=8.1',
        'Linter' => '>=8.1',
        'Optimizer' => '>=8.1',
        'Parser' => '>=8.1',
        'PHPStan' => '>=8.1',
        'PHPUnit' => '>=8.1',
        'Psalm' => '>=8.1',
        'Rector' => '>=8.1',
        'Redos' => '>=8.1',
        'Symfony' => '>=8.1',
        'Toolkit' => '>=8.1',
        'Transpiler' => '>=8.1',
    ];

    /**
     * The uses of a sibling's internal classes 2.0 ships with: every class
     * of another package whose docblock says @internal, wherever its
     * namespace puts it. Siblings always run at the same version, so these
     * may change in any release; a new entry here is still a new
     * cross-package dependency on code that promises nothing: make the class
     * public instead, or keep it. Ascii and Bytes map case and split strings
     * the same way on every PHP the packages run on. Parser\Hir stays internal
     * while the analyses move onto the normalized form.
     */
    private const INTERNAL_USES = [
        // The solver reads the whole normalized form: every class of
        // it, and the translator that builds the tree.
        'Automata' => [
            'Parser\Exception\VisualContextTrait', 'Parser\Hir\AlternationHir', 'Parser\Hir\AssertionHir',
            'Parser\Hir\AssertionKind', 'Parser\Hir\AtomicHir', 'Parser\Hir\CaptureHir', 'Parser\Hir\CharSet',
            'Parser\Hir\ClassHir', 'Parser\Hir\ConcatHir', 'Parser\Hir\ConditionalHir', 'Parser\Hir\EmptyHir',
            'Parser\Hir\Greed', 'Parser\Hir\Hir', 'Parser\Hir\HirTranslator', 'Parser\Hir\LiteralHir',
            'Parser\Hir\LookHir', 'Parser\Hir\LookKind', 'Parser\Hir\OpaqueHir', 'Parser\Hir\RepetitionHir',
            'Parser\Text\Bytes', 'Parser\Engine\LibraryPcre', 'Parser\Syntax\StartOptions',
            'Parser\Cache\StaticCaches', 'Parser\NodeWalker', 'Parser\TraversalAction',
        ],
        // The CLI, the PHPStan rule and the Psalm plugin name the directory
        // where the class sets PCRE2 answered are kept between runs.
        'Cli' => [
            'Automata\Model\Nfa', 'Automata\Model\NfaState', 'Automata\Transform\HirToNfaTransformer',
            'LanguageServer\Server', 'Linter\AnalysisService', 'Linter\Config\LintArgumentParser',
            'Linter\Config\LintArguments', 'Linter\Config\LintConfigLoader', 'Linter\Config\LintDefaultsBuilder',
            'Linter\Config\LintExtractorFactory', 'Linter\Config\ProjectTarget',
            'Linter\Config\UnsupportedTargetException', 'Linter\Diagnostic', 'Linter\DiagnosticType',
            'Linter\Formatter\ConsoleFormatter', 'Linter\Formatter\FormatterRegistry', 'Linter\Formatter\JsonFormatter',
            'Linter\Formatter\LinkFormatter', 'Linter\Formatter\OutputConfiguration',
            'Linter\Formatter\OutputFormatterInterface', 'Linter\Formatter\RelativePathHelper',
            'Linter\LintStatsCounter', 'Linter\Formatter\LintSummary', 'Linter\RedosVerdict',
            'Linter\Parallel\WorkerCount', 'Linter\LintProgress', 'Linter\LintReport', 'Linter\LintRequest',
            'Linter\LintService', 'Linter\PatternOccurrence', 'Linter\Source\PatternSourceCollection',
            'Linter\Source\PhpFilePatternSource', 'Parser\Hir\CharSet', 'Parser\Hir\ClassSetProvider',
            'Parser\Hir\HirTranslator', 'Parser\Text\Ascii', 'Parser\Text\DisplayEscaper', 'Parser\Engine\IniFlag',
            'Parser\Json\JsonDocument', 'Parser\Engine\LibraryPcre', 'Parser\Text\NearMiss',
            'Parser\Syntax\PatternParser', 'Redos\Heatmap', 'Redos\InputGenerator',
            'Transpiler\Target\TargetRegistry',
        ],
        'Explain' => ['Parser\Text\Ascii', 'Parser\Text\Bytes', 'Parser\Text\DisplayEscaper', 'Parser\Engine\LibraryPcre'],
        'Generator' => ['Parser\Text\Ascii', 'Parser\Text\Bytes', 'Parser\Engine\LibraryPcre', 'Parser\Cache\StaticCaches'],
        // The library's own regexes run under the PCRE floor in every
        // package that runs one: the floor helper is the one shared
        // internal they all use for it.
        'LanguageServer' => [
            'Linter\Config\JudgedRange', 'Linter\Config\LintArguments', 'Linter\Config\LintConfigLoader',
            'Linter\Config\LintConfigResult', 'Linter\Config\LintConfigSchema', 'Linter\Config\LintDefaultsBuilder',
            'Linter\Config\ProjectTarget', 'Linter\Config\TargetConstraint', 'Linter\Config\UnsupportedTargetException',
            'Linter\Extraction\IgnoreMarker', 'Linter\Extraction\InlineIgnores', 'Linter\Extraction\LiteralSpelling',
            'Linter\Extraction\MemoryBudget', 'Linter\Extraction\PatternAttributeScanner',
            'Linter\Extraction\PatternFunctionRegistry', 'Linter\Extraction\PhpStringLiteral',
            'Linter\Extraction\TargetMarker', 'Linter\Extraction\TargetMarkers',
            'Linter\Extraction\TokenBasedExtractionStrategy', 'Linter\RedosVerdict',
            'Linter\Rule\InvisibleCharacterRule', 'Parser\Text\Ascii', 'Parser\Engine\IniFlag',
            'Parser\Engine\LibraryPcre',
        ],
        'Laravel' => [
            'Linter\AnalysisService', 'Linter\Config\ProjectTarget', 'Linter\Config\UnsupportedTargetException',
            'Linter\Diagnostic', 'Linter\DiagnosticType', 'Linter\Extraction\ExtractorInterface',
            'Linter\Extraction\InteropPresets', 'Linter\Extraction\PatternFunctionRegistry',
            'Linter\Extraction\PhpParserExtractionStrategy', 'Linter\Extraction\RouteRequirementCompiler', 'Linter\Extraction\TokenBasedExtractionStrategy',
            'Linter\Formatter\AbstractConsoleTagFormatter', 'Linter\Formatter\FormatterRegistry',
            'Linter\Formatter\JsonFormatter', 'Linter\Formatter\LinkFormatter', 'Linter\Formatter\RelativePathHelper',
            'Linter\LintStatsCounter', 'Linter\Parallel\WorkerCount', 'Linter\LintProgress',
            'Linter\LintReport', 'Linter\LintRequest', 'Linter\LintService', 'Linter\PatternExtractor',
            'Linter\PatternOccurrence', 'Linter\Source\PatternSourceCollection', 'Linter\Source\PatternSourceContext',
            'Linter\Source\PatternSourceInterface', 'Linter\Source\PhpFilePatternSource',
            'Parser\Text\DisplayEscaper', 'Parser\Json\JsonDocument', 'Parser\Engine\LibraryPcre',
            'Transpiler\Target\TargetRegistry',
        ],
        // The lint writes its rewrite of a pattern under x into the
        // pattern's own layout, as the optimizer does, and checks it as
        // the optimizer checks its own.
        'Linter' => [
            'Optimizer\LayoutSplicer', 'Optimizer\RewriteCheck', 'Optimizer\Rewriter',
            'Parser\Analysis\ByteCharSet', 'Parser\Analysis\CharSetAnalyzer', 'Parser\Cache\AstSerializer',
            'Parser\Text\Ascii', 'Parser\Text\Bytes', 'Parser\Text\DisplayEscaper',
            'Parser\Analysis\GroupIndex', 'Parser\Json\JsonDocument', 'Parser\Json\JsonEncodingFailure',
            'Parser\Engine\LibraryPcre', 'Parser\Text\NearMiss', 'Parser\Syntax\PatternParser',
            'Parser\Syntax\PropertyNames', 'Parser\Syntax\StartOptions', 'Parser\Cache\StaticCaches',
            'Parser\Lexer', 'Parser\NodeWalker', 'Parser\TraversalAction',
        ],
        'Optimizer' => ['Parser\Analysis\CharSetAnalyzer', 'Parser\Engine\LibraryPcre', 'Parser\Syntax\PatternParser', 'Parser\NodeWalker', 'Parser\TraversalAction'],
        'PHPStan' => [
            'Linter\AnalysisService', 'Linter\Config\JudgedRange', 'Linter\Config\ProjectTarget',
            'Linter\Config\TargetConstraint', 'Linter\Config\UnsupportedTargetException',
            'Linter\Extraction\InteropPresets', 'Linter\Extraction\PatternFunction',
            'Linter\Extraction\PatternFunctionRegistry', 'Linter\Extraction\TargetMarker',
            'Linter\Extraction\TargetMarkers', 'Linter\PatternOccurrence', 'Parser\Hir\ClassSetProvider',
            'Parser\Text\Ascii', 'Parser\Text\DisplayEscaper', 'Parser\Engine\LibraryPcre',
            'Linter\Extraction\PatternAttributeScanner', 'Linter\Extraction\PregFunctions',
        ],
        // The plugin reads the key layout CaptureShape renders for
        // PHPStan, so both type systems say the same keys.
        'Psalm' => [
            'Linter\Config\JudgedRange', 'Linter\Config\ProjectTarget', 'Linter\Config\UnsupportedTargetException',
            'Linter\Extraction\GuardRegions', 'Linter\Extraction\PhpParserGuards', 'Linter\Extraction\TargetMarker',
            'Linter\Extraction\TargetMarkers', 'Parser\Hir\ClassSetProvider', 'Parser\Text\Ascii',
            'Parser\Analysis\CaptureKey', 'Linter\Extraction\PregFunctions',
            'Parser\Analysis\CaptureLayout', 'Parser\Engine\LibraryPcre',
        ],
        'Redos' => [
            'Parser\Analysis\ByteCharSet', 'Parser\Analysis\CharSetAnalyzer', 'Parser\Hir\CharSet',
            'Parser\Hir\ClassSetProvider', 'Parser\Hir\Utf8', 'Parser\Text\Ascii', 'Parser\Text\Bytes',
            'Parser\Engine\IniFlag', 'Parser\Syntax\PatternParser',
            // The verb the engine puts in front of the pattern, which the
            // witness replays swap for their own run of verbs.
            'Parser\Engine\NoJit',
        ],
        'Symfony' => [
            'Linter\AnalysisService', 'Linter\Config\ProjectTarget', 'Linter\Config\UnsupportedTargetException',
            'Linter\Extraction\ExtractorInterface', 'Linter\Extraction\InteropPresets',
            'Linter\Extraction\PatternFunctionRegistry', 'Linter\Extraction\PhpParserExtractionStrategy', 'Linter\Extraction\RouteRequirementCompiler',
            'Linter\Extraction\TokenBasedExtractionStrategy', 'Linter\Formatter\AbstractConsoleTagFormatter',
            'Linter\Formatter\FormatterRegistry', 'Linter\Formatter\JsonFormatter', 'Linter\Formatter\LinkFormatter',
            'Linter\Formatter\RelativePathHelper', 'Linter\Parallel\WorkerCount', 'Linter\LintProgress',
            'Linter\LintReport', 'Linter\LintRequest', 'Linter\LintService', 'Linter\PatternExtractor',
            'Linter\PatternOccurrence', 'Linter\Source\OverlappingPatternSourceInterface',
            'Linter\Source\PatternSourceCollection', 'Linter\Source\PatternSourceContext',
            'Linter\Source\PatternSourceInterface', 'Linter\Source\PhpFilePatternSource',
            'Parser\Text\DisplayEscaper', 'Parser\Json\JsonDocument', 'Parser\Engine\LibraryPcre',
            'Transpiler\Target\TargetRegistry',
        ],
        // The HTML pattern attribute takes no flag: /i is spelled out
        // with the characters the running PCRE takes for each atom.
        'Transpiler' => [
            'Parser\Exception\VisualContextTrait', 'Parser\Hir\CharSet', 'Parser\Hir\ClassSetProvider',
            'Parser\Hir\Utf8', 'Parser\Text\Ascii', 'Parser\Text\Bytes', 'Parser\Engine\LibraryPcre',
        ],
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
        $this->assertSame(self::PHP_FLOORS[$directory], self::dig($manifest, 'require', 'php'), $directory);
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

    /**
     * The upgrade guide ships with the monorepo package only: a split
     * install carries no copy, so its README links the guide on the 2.x
     * branch, the file this repository holds.
     */
    #[Test]
    #[DataProvider('provideDirectories')]
    public function test_a_package_readme_links_the_upgrade_guide(string $directory): void
    {
        $readme = (string) file_get_contents(self::root().'/src/'.$directory.'/README.md');

        $this->assertFileExists(self::root().'/UPGRADE-2.0.md');
        $this->assertStringContainsString('(https://github.com/php-regex/php-regex/blob/2.x/UPGRADE-2.0.md)', $readme, \sprintf('src/%s/README.md does not link the upgrade guide.', $directory));
    }

    /**
     * The README banners are read from the repository, never from an
     * install: a package leaves them out of its archive, as it does its
     * tests.
     */
    #[Test]
    #[DataProvider('provideDirectories')]
    public function test_a_package_leaves_its_tests_and_banners_out_of_its_archive(string $directory): void
    {
        $path = self::root().'/src/'.$directory;
        $attributes = (string) file_get_contents($path.'/.gitattributes');

        if (is_dir($path.'/Tests')) {
            $this->assertMatchesRegularExpression('~^/Tests\s+export-ignore$~m', $attributes);
        }
        if (is_dir($path.'/art')) {
            $this->assertMatchesRegularExpression('~^/art\s+export-ignore$~m', $attributes, \sprintf('src/%s/.gitattributes ships the banners.', $directory));
        } else {
            $this->assertDirectoryDoesNotExist($path.'/art');
        }
    }

    /**
     * What a package's archive leaves out is what the package holds: a line
     * naming a path it does not have tells a reader of the file something
     * false about the package.
     */
    #[Test]
    #[DataProvider('provideDirectories')]
    public function test_a_package_leaves_out_of_its_archive_only_paths_it_has(string $directory): void
    {
        $path = self::root().'/src/'.$directory;
        preg_match_all('~^/(\S+)\s+export-ignore$~m', (string) file_get_contents($path.'/.gitattributes'), $ignored);

        $missing = array_values(array_filter($ignored[1], static fn (string $entry): bool => !file_exists($path.'/'.$entry)));

        $this->assertSame([], $missing, \sprintf('src/%s/.gitattributes leaves out paths the package does not have.', $directory));
    }

    #[Test]
    public function test_a_package_ships_the_files_its_manifest_names(): void
    {
        $this->assertSame(['extension.neon'], self::dig(self::manifest('PHPStan'), 'extra', 'phpstan', 'includes'));
        $this->assertFileExists(self::root().'/src/PHPStan/extension.neon');
        $this->assertFileExists(self::root().'/src/PHPStan/rules.neon');
        // The linter reads regex.json, so it ships the file's schema.
        $this->assertFileExists(self::root().'/src/Linter/regex.schema.json');
        // The parser exports trees as JSON, so it ships the document's schema.
        $this->assertFileExists(self::root().'/src/Parser/ast.schema.json');
        // The IDE completion files ship with the package whose API they
        // complete: an install of it, split or monorepo, carries the file
        // the editor reads from the vendor tree.
        foreach (['Parser', 'Redos', 'Toolkit'] as $directory) {
            $this->assertFileExists(self::root().'/src/'.$directory.'/.phpstorm.meta.php');
        }

        foreach (['Cli' => 'bin/regex', 'LanguageServer' => 'bin/regex-lsp'] as $directory => $script) {
            $this->assertSame([$script], self::dig(self::manifest($directory), 'bin'));
            $this->assertFileIsReadable(self::root().'/src/'.$directory.'/'.$script);
            $this->assertTrue(is_executable(self::root().'/src/'.$directory.'/'.$script), $script);
        }
    }

    /**
     * The linter reads PHP with nikic/php-parser when it is installed, through
     * ParserFactory::createForHostVersion(), which arrived in 4.18: an older
     * copy installed for another reason would end the lint with a fatal
     * error, so the linter and the monorepo package refuse it.
     */
    #[Test]
    public function test_linter_refuses_a_php_parser_without_create_for_host_version(): void
    {
        $this->assertSame('<4.18', self::dig(self::manifest('Linter'), 'conflict', 'nikic/php-parser'));
        $this->assertSame('<4.18', self::dig(self::decode(self::root().'/composer.json'), 'conflict', 'nikic/php-parser'));
    }

    /**
     * The monorepo package carries every bridge, but only its own manifest
     * reaches a project that installs it: a host release older than every
     * package that requires the host takes is refused there, as the
     * packages' own manifests refuse it, and so is a release a package
     * conflicts with. Without it, a Laravel 9 application would install the
     * monorepo package and load the provider its package discovery finds,
     * on an Illuminate the bridge was never written for.
     */
    #[Test]
    public function test_the_monorepo_package_refuses_the_hosts_its_bridges_refuse(): void
    {
        $floors = [];
        $conflicts = [];
        foreach (array_keys(self::PACKAGES) as $directory) {
            $manifest = self::manifest($directory);
            foreach (self::strings(self::dig($manifest, 'require')) as $package => $constraint) {
                if ('php' === $package || str_starts_with($package, 'ext-') || str_starts_with($package, 'php-regex/')) {
                    continue;
                }
                $floor = self::lowestRelease($constraint);
                if (!isset($floors[$package]) || version_compare($floor, $floors[$package], '<')) {
                    $floors[$package] = $floor;
                }
            }
            foreach (self::strings(self::dig($manifest, 'conflict')) as $package => $constraint) {
                $conflicts[$package] = $constraint;
            }
        }
        foreach ($floors as $package => $floor) {
            $conflicts[$package] ??= '<'.$floor;
        }
        ksort($conflicts);

        $root = self::strings(self::dig(self::decode(self::root().'/composer.json'), 'conflict'));
        ksort($root);

        $this->assertSame($conflicts, $root);
        // The PHPStan bridge's neon is written on the config keys PHPStan 2
        // alone reads, and the extension installer includes it on every
        // PHPStan it finds: the host is refused before it loads it.
        $this->assertSame('<2.0', $root['phpstan/phpstan']);
        $this->assertSame('<10.37', $root['illuminate/support']);
        $this->assertSame('<6.4', $root['symfony/http-kernel']);
    }

    /**
     * The monorepo package carries every bridge, but only its own manifest
     * reaches the project that installs it: the discovery keys the bridges'
     * packages carry — Laravel's package discovery, the PHPStan extension
     * installer, the psalm-plugin CLI — are aggregated here, and nothing
     * else lives in extra.
     */
    #[Test]
    public function test_the_monorepo_package_aggregates_its_bridges_discovery_keys(): void
    {
        $root = self::decode(self::root().'/composer.json');

        $this->assertSame(['branch-alias', 'laravel', 'phpstan', 'psalm'], self::keysOf($root, 'extra'));
        $this->assertSame(self::dig(self::manifest('Laravel'), 'extra', 'laravel'), self::dig($root, 'extra', 'laravel'));
        $this->assertSame(['src/PHPStan/extension.neon'], self::dig($root, 'extra', 'phpstan', 'includes'));
        $this->assertSame(Plugin::class, self::dig($root, 'extra', 'psalm', 'pluginClass'));
    }

    #[Test]
    public function test_rector_package_is_a_rector_extension_on_rector_2(): void
    {
        $manifest = self::manifest('Rector');

        $this->assertSame('rector-extension', self::dig($manifest, 'type'));
        // AbstractRector::getNativeType(), which the trivial-match rule reads,
        // arrived in Rector 2.4.
        $this->assertSame('^2.4', self::dig($manifest, 'require', 'rector/rector'));
        // Rector bundles the parser it runs on: requiring another copy could
        // only conflict with it.
        $this->assertNull(self::dig($manifest, 'require', 'nikic/php-parser'));
        $this->assertFileExists(self::root().'/src/Rector/config/sets/string-functions.php');
        $this->assertFileExists(self::root().'/src/Rector/config/sets/upgrade-2.0.php');
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
    public function test_phpunit_package_takes_every_supported_phpunit_major(): void
    {
        $manifest = self::manifest('PHPUnit');

        // 9.6 is the settled floor, the oldest the assertions still serve;
        // every major from it runs on some PHP the package runs on, so all
        // five are required, not suggested.
        $this->assertSame('^9.6|^10.0|^11.0|^12.0|^13.0', self::dig($manifest, 'require', 'phpunit/phpunit'));
    }

    /**
     * ParaTest runs the suite with the classes of its own vendor: it needs
     * every package the suite needs, at the same constraints as the PHPUnit
     * tool, and ParaTest itself.
     */
    #[Test]
    public function test_paratest_tool_requires_what_the_phpunit_tool_requires(): void
    {
        $phpunit = self::decode(self::root().'/tools/phpunit/composer.json');
        $paratest = self::decode(self::root().'/tools/paratest/composer.json');
        $required = self::dig($paratest, 'require-dev');
        $this->assertIsArray($required);
        $this->assertSame('^7.8', $required['brianium/paratest'] ?? null);
        unset($required['brianium/paratest']);

        $this->assertSame(self::dig($phpunit, 'require-dev'), $required);
        $this->assertSame(self::dig($phpunit, 'config'), self::dig($paratest, 'config'));
    }

    #[Test]
    public function test_every_manifest_writes_its_alternatives_with_a_single_pipe(): void
    {
        $root = self::root();
        $manifests = [$root.'/composer.json', ...glob($root.'/{src,tools,demo}/*/composer.json', \GLOB_BRACE) ?: []];
        $this->assertGreaterThan(25, \count($manifests));

        // Composer reads "|" and "||" alike; the manifests keep one spelling,
        // the "^6.4|^7.4" one, and a dependency update that writes " || "
        // is rewritten before it lands.
        $spelled = [];
        foreach ($manifests as $file) {
            $manifest = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
            \assert(\is_array($manifest));
            foreach (['require', 'require-dev', 'conflict', 'replace', 'provide'] as $section) {
                $links = $manifest[$section] ?? [];
                \assert(\is_array($links));
                foreach ($links as $package => $constraint) {
                    if (\is_string($constraint) && (str_contains($constraint, '||') || str_contains($constraint, ' |') || str_contains($constraint, '| '))) {
                        $spelled[] = substr($file, \strlen($root) + 1).' '.$section.' '.$package.': '.$constraint;
                    }
                }
            }
        }

        $this->assertSame([], $spelled);
    }

    #[Test]
    public function test_deptrac_has_a_layer_and_a_ruleset_per_package(): void
    {
        $config = (string) file_get_contents(self::root().'/deptrac.yaml');
        $layers = self::deptracLayers($config);

        $byDirectory = [];
        $internalOf = [];
        foreach ($layers as $layer => ['directory' => $directory, 'internal' => $internal]) {
            if ($internal) {
                $internalOf[$directory] = $layer;
            } else {
                $byDirectory[$directory] = $layer;
            }
        }
        ksort($byDirectory);
        $expected = array_keys(self::PACKAGES);
        sort($expected);

        $this->assertSame($expected, array_keys($byDirectory));

        // A package with an internal layer splits on the tag, not on a
        // namespace: its public layer is the package minus what says
        // @internal, so a class changes layer by losing the tag, never by
        // moving.
        foreach ($internalOf as $directory => $layer) {
            $this->assertArrayHasKey($directory, $byDirectory, \sprintf('deptrac.yaml: the %s layer has no public layer beside it.', $layer));
            $public = $layers[$byDirectory[$directory]]['block'];
            $this->assertMatchesRegularExpression("~must_not:\\s+- type: tagValueRegex\\s+tag: '@internal'~", $public, \sprintf('deptrac.yaml: the %s layer must leave out what says @internal.', $byDirectory[$directory]));
            $this->assertMatchesRegularExpression("~must:\\s+- type: classNameRegex\\s+value: '[^']+'\\s+- type: tagValueRegex\\s+tag: '@internal'~", $layers[$layer]['block'], \sprintf('deptrac.yaml: the %s layer must be its package and @internal.', $layer));
        }

        $ruleset = substr($config, (int) strpos($config, "\n  ruleset:"));
        foreach ([...$byDirectory, ...array_values($internalOf)] as $directory => $layer) {
            $this->assertMatchesRegularExpression('~^    '.$layer.': ~m', $ruleset, \sprintf('deptrac.yaml: the %s layer (src/%s) has no ruleset entry.', $layer, $directory));
        }
    }

    #[Test]
    public function test_no_new_use_of_another_package_internals(): void
    {
        $allowed = self::INTERNAL_USES;
        $internal = self::internalClasses();

        $found = [];
        foreach (array_keys(self::PACKAGES) as $directory) {
            $uses = [];
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/src/'.$directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                    preg_match_all('~PHPRegex\\\\(\w+(?:\\\\\w+)+)~', (string) file_get_contents($file->getPathname()), $matches);
                    foreach ($matches[1] as $name) {
                        if (isset($internal[$name]) && $internal[$name] !== $directory) {
                            $uses[] = $name;
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

    /**
     * The architecture rules say the same at the package level: a package may
     * reach a sibling's internal layer exactly when it uses a listed class of
     * it, so a new import of one fails there too.
     */
    #[Test]
    public function test_deptrac_grants_a_sibling_internal_layer_only_to_its_listed_users(): void
    {
        $config = (string) file_get_contents(self::root().'/deptrac.yaml');
        $directoryOf = [];
        $internalOf = [];
        foreach (self::deptracLayers($config) as $layer => ['directory' => $directory, 'internal' => $internal]) {
            $directoryOf[$layer] = $directory;
            if ($internal) {
                $internalOf[$directory] = $layer;
            }
        }

        $ruleset = substr($config, (int) strpos($config, "\n  ruleset:"));
        preg_match_all('~^    (\\w+): \\[([^\\]]*)\\]$~m', $ruleset, $rules, \PREG_SET_ORDER);
        $granted = [];
        foreach ($rules as [, $layer, $targets]) {
            foreach (array_map(trim(...), explode(',', $targets)) as $target) {
                if (isset($directoryOf[$layer], $directoryOf[$target]) && \in_array($target, $internalOf, true) && $directoryOf[$target] !== $directoryOf[$layer]) {
                    $granted[$directoryOf[$layer]][] = $target;
                }
            }
        }

        // A use of a package without an internal layer is listed above and
        // needs no grant: that package's layer holds all of it.
        $expected = [];
        foreach (self::INTERNAL_USES as $directory => $uses) {
            foreach ($uses as $use) {
                $package = explode('\\', $use)[0];
                if (isset($internalOf[$package])) {
                    $expected[$directory][] = $internalOf[$package];
                }
            }
        }

        foreach ([&$granted, &$expected] as &$map) {
            ksort($map);
            foreach ($map as &$targets) {
                $targets = array_values(array_unique($targets));
                sort($targets);
            }
        }

        $this->assertSame($expected, $granted, 'deptrac.yaml grants a sibling\'s internal layer to a package that does not use it, or misses one it uses.');
    }

    /**
     * A package's layers, public and internal, reach the siblings its manifest
     * requires, and the one it suggests and starts by class name when it is
     * installed, no more: a grant the manifest does not back would let
     * through an import a split install of the package cannot load.
     */
    #[Test]
    public function test_deptrac_grants_a_package_exactly_the_siblings_its_manifest_names(): void
    {
        $config = (string) file_get_contents(self::root().'/deptrac.yaml');
        $layers = self::deptracLayers($config);
        $directories = array_flip(self::PACKAGES);

        $ruleset = substr($config, (int) strpos($config, "\n  ruleset:"));
        preg_match_all('~^    (\\w+): \\[([^\\]]*)\\]$~m', $ruleset, $rules, \PREG_SET_ORDER);
        $granted = [];
        $expected = [];
        foreach ($rules as [, $layer, $targets]) {
            if (!isset($layers[$layer])) {
                continue;
            }
            $directory = $layers[$layer]['directory'];

            $siblings = [];
            foreach (array_map(trim(...), explode(',', $targets)) as $target) {
                if (isset($layers[$target]) && $layers[$target]['directory'] !== $directory) {
                    $siblings[] = $layers[$target]['directory'];
                }
            }
            $siblings = array_values(array_unique($siblings));
            sort($siblings);
            $granted[$layer] = $siblings;

            $manifest = self::manifest($directory);
            $named = [];
            foreach ([...array_keys(self::strings(self::dig($manifest, 'require'))), ...array_keys(self::strings(self::dig($manifest, 'suggest')))] as $package) {
                if (str_starts_with($package, 'php-regex/')) {
                    $named[] = $directories[substr($package, \strlen('php-regex/'))];
                }
            }
            sort($named);
            $expected[$layer] = $named;
        }
        ksort($granted);
        ksort($expected);

        $this->assertSame($expected, $granted, 'deptrac.yaml lets a package reach a sibling its composer.json does not name, or misses one it names.');
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
        // The source URL is the identity anchor Packagist consumes; the
        // homepage is a marketing domain that may move.
        $this->assertSame('https://github.com/php-regex/php-regex', self::dig($root, 'support', 'source'));
    }

    #[Test]
    #[DataProvider('provideDirectories')]
    public function test_a_package_runs_on_every_php_its_siblings_run_on(string $directory): void
    {
        foreach (self::strings(self::dig(self::manifest($directory), 'require')) as $package => $constraint) {
            $sibling = array_search(substr($package, \strlen('php-regex/')), self::PACKAGES, true);
            if (str_starts_with($package, 'php-regex/') && \is_string($sibling)) {
                $this->assertLessThanOrEqual(self::floor(self::PHP_FLOORS[$directory]), self::floor(self::PHP_FLOORS[$sibling]), $directory.' requires '.$package);
            }
        }
    }

    #[Test]
    public function test_the_cli_binary_refuses_the_php_its_package_refuses(): void
    {
        $script = (string) file_get_contents(self::root().'/src/Cli/bin/regex');

        $this->assertSame(1, preg_match("~version_compare\\('(\\d+\\.\\d+)\\.0', \\\\PHP_VERSION, '>'\\)~", $script, $floor));
        $this->assertSame(self::floor(self::PHP_FLOORS['Cli']), $floor[1]);
        $this->assertStringContainsString('requires PHP >= '.$floor[1].'.0.', $script);
    }

    /**
     * Symfony 6.4, the LTS that runs on PHP 8.1, then the supported 7.4 and
     * 8; Laravel 10.37, the first the tests run on PHP 8.1, to 13.
     */
    #[Test]
    #[DataProvider('provideFrameworkBridges')]
    public function test_a_bridge_takes_every_supported_release_of_its_framework(string $directory, string $prefix, string $constraint): void
    {
        $found = 0;
        foreach (self::strings(self::dig(self::manifest($directory), 'require')) as $package => $required) {
            if (str_starts_with($package, $prefix)) {
                $this->assertSame($constraint, $required, $package);
                $found++;
            }
        }

        $this->assertGreaterThan(0, $found, $directory.' requires no '.$prefix.' package.');
    }

    /**
     * @return iterable<string, array{directory: string, prefix: string, constraint: string}>
     */
    public static function provideFrameworkBridges(): iterable
    {
        yield 'Symfony bundle' => ['directory' => 'Symfony', 'prefix' => 'symfony/', 'constraint' => '^6.4|^7.4|^8.0'];
        yield 'Laravel on Symfony Console' => ['directory' => 'Laravel', 'prefix' => 'symfony/', 'constraint' => '^6.4|^7.4|^8.0'];
        yield 'Laravel' => ['directory' => 'Laravel', 'prefix' => 'illuminate/', 'constraint' => '^10.37|^11.0|^12.0|^13.0'];
    }

    #[Test]
    public function test_the_monorepo_package_takes_the_highest_php_floor_of_its_packages(): void
    {
        $floors = array_map(self::floor(...), self::PHP_FLOORS);
        $root = self::dig(self::decode(self::root().'/composer.json'), 'require', 'php');

        $this->assertIsString($root);
        $this->assertSame(max($floors), self::floor($root));
        $this->assertSame('>=8.1', $root);
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
     * Every package is a page of its own on Packagist: the author and the
     * funding link the monorepo declares, the seventeen packages declare
     * too.
     */
    #[Test]
    public function test_every_package_carries_the_monorepos_author_and_funding(): void
    {
        $root = self::decode(self::root().'/composer.json');

        foreach (array_keys(self::PACKAGES) as $directory) {
            $manifest = self::manifest($directory);

            $this->assertSame(self::dig($root, 'authors'), self::dig($manifest, 'authors'), $directory);
            $this->assertSame(self::dig($root, 'funding'), self::dig($manifest, 'funding'), $directory);
        }
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
     * The oldest release a constraint written as caret alternatives takes:
     * "^10.37|^11.0" takes 10.37 and later.
     */
    private static function lowestRelease(string $constraint): string
    {
        $releases = array_map(static function (string $alternative) use ($constraint): string {
            self::assertMatchesRegularExpression('~^\^\d+(?:\.\d+)+$~', $alternative, \sprintf('"%s" is not written as caret alternatives.', $constraint));

            return substr($alternative, 1);
        }, explode('|', $constraint));
        $lowest = $releases[0];
        foreach ($releases as $release) {
            if (version_compare($release, $lowest, '<')) {
                $lowest = $release;
            }
        }

        return $lowest;
    }

    private static function floor(string $constraint): string
    {
        return substr($constraint, \strlen('>='));
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
     * The keys of the block a path of keys leads to, narrowed from the
     * mixed the manifest decodes to.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function keysOf(array $data, string ...$keys): array
    {
        $block = self::dig($data, ...$keys);
        self::assertIsArray($block);

        return array_map(strval(...), array_keys($block));
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
     * Each deptrac layer, with the src/ directory its classes come from and
     * whether it collects the package's @internal classes.
     *
     * @return array<string, array{directory: string, internal: bool, block: string}>
     */
    private static function deptracLayers(string $config): array
    {
        $section = substr($config, 0, (int) strpos($config, "\n  ruleset:"))."\n";
        preg_match_all('~^    - name: (\w+)\n((?:      .*\n)+)~m', $section, $blocks, \PREG_SET_ORDER);

        $layers = [];
        foreach ($blocks as [, $layer, $block]) {
            if (1 === preg_match('~PHPRegex\W+(\w+)~', $block, $directory)) {
                $layers[$layer] = [
                    'directory' => $directory[1],
                    'internal' => str_contains($block, 'tagValueRegex') && !str_contains($block, 'must_not'),
                    'block' => $block,
                ];
            }
        }

        return $layers;
    }

    /**
     * Every class under src/ whose docblock says @internal, by its name
     * without the PHPRegex prefix, with the package it belongs to.
     *
     * @return array<string, string>
     */
    private static function internalClasses(): array
    {
        $internal = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (1 !== preg_match('~^namespace PHPRegex\\\\((\w+)[\w\\\\]*);~m', $code, $namespace)
                || 1 !== preg_match('~(/\*\*(?:(?!\*/).)*\*/)\s*(?:#\[[^\]]*\]\s*)*(?:final |abstract |readonly )*(?:class|interface|trait|enum) (\w+)~s', $code, $declaration)
                || 1 !== preg_match('~^\s*\*\s*@internal\b~m', $declaration[1])) {
                continue;
            }
            $internal[$namespace[1].'\\'.$declaration[2]] = $namespace[2];
        }

        return $internal;
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
            'PHPUnit' => 'phpunit/phpunit',
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
