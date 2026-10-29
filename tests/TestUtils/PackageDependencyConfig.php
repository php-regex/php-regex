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

namespace PHPRegex\Tests\TestUtils;

/**
 * The configuration the dependency analyser runs with on one package
 * installed alone in a bare project: the installed copy is the only path
 * scanned, and what the manifest suggests may be used without being
 * required. Everything else the sources use must be in the manifest.
 *
 * Two facts of the ecosystem are written in: a bridge reaches php-parser
 * through its host, PHPStan and Rector inside their phar and Psalm as its
 * own requirement, never as a requirement of its own, which could only
 * conflict with the host's; and the Symfony container reads its PHP
 * configuration through functions its loader declares, which no
 * autoloader can find.
 */
final class PackageDependencyConfig
{
    /**
     * The hosts whose phar bundles php-parser: installed beside the phar for
     * the analysis to read it.
     */
    private const PHP_PARSER_PHARS = ['phpstan/phpstan', 'rector/rector'];

    /**
     * The hosts that require php-parser: already installed with them.
     */
    private const PHP_PARSER_HOSTS = ['vimeo/psalm'];

    private function __construct() {}

    /**
     * @param array<mixed> $manifest   the package's composer.json, decoded
     * @param string       $vendorPath the installed copy, relative to the project
     *
     * @return string the PHP of the configuration file
     */
    public static function configuration(array $manifest, string $vendorPath): string
    {
        $name = \is_string($manifest['name'] ?? null) ? $manifest['name'] : '';
        $packages = [];
        $extensions = [];
        foreach (self::keys($manifest['suggest'] ?? null) as $suggested) {
            if (str_starts_with($suggested, 'ext-')) {
                $extensions[] = $suggested;
            } else {
                $packages[] = $suggested;
            }
        }

        $lines = [
            '<?php',
            '',
            'use ShipMonk\\ComposerDependencyAnalyser\\Config\\Configuration;',
            'use ShipMonk\\ComposerDependencyAnalyser\\Config\\ErrorType;',
            '',
            '$configuration = (new Configuration())',
            '    ->disableComposerAutoloadPathScan()',
            '    ->addPathToScan(__DIR__.'.self::quote('/'.$vendorPath).', isDev: false)',
            '    // the package reads its own classes',
            '    ->ignoreErrorsOnPackage('.self::quote($name).', [ErrorType::SHADOW_DEPENDENCY])',
            '    // an allowance the sources never use is no finding: a suggestion',
            '    // left unused, a phar exemption on a bridge that stops importing',
            '    ->disableReportingUnmatchedIgnores()',
        ];
        if ([] !== $packages) {
            $lines[] = '    ->ignoreErrorsOnPackages('.self::list($packages).', [ErrorType::SHADOW_DEPENDENCY])';
        }
        if ([] !== $extensions) {
            $lines[] = '    ->ignoreErrorsOnExtensions('.self::list($extensions).', [ErrorType::SHADOW_DEPENDENCY])';
        }
        if (self::bundlesPhpParser($manifest) || self::requiresPhpParser($manifest)) {
            $lines[] = '    // the host carries php-parser, which the sources reach through it';
            $lines[] = "    ->ignoreErrorsOnPackage('nikic/php-parser', [ErrorType::SHADOW_DEPENDENCY])";
        }
        if (\in_array('symfony/dependency-injection', self::keys($manifest['require'] ?? null), true)) {
            $lines[] = '    // the container configuration calls the functions its loader declares';
            $lines[] = '    ->ignoreErrorsOnPath(__DIR__.'.self::quote('/'.$vendorPath.'/Resources/config').', [ErrorType::UNKNOWN_FUNCTION])';
        }
        $lines[\count($lines) - 1] .= ';';
        $lines[] = '';
        $lines[] = 'return $configuration;';

        return implode("\n", $lines)."\n";
    }

    /**
     * The packages installed beside the package for the analysis to read
     * them: what the manifest suggests, and php-parser where a phar
     * provides it at runtime.
     *
     * @param array<mixed> $manifest
     *
     * @return list<string>
     */
    public static function helpers(array $manifest): array
    {
        $helpers = [];
        foreach (self::keys($manifest['suggest'] ?? null) as $suggested) {
            if (!str_starts_with($suggested, 'ext-')) {
                $helpers[] = $suggested;
            }
        }
        if (self::bundlesPhpParser($manifest) && !\in_array('nikic/php-parser', $helpers, true)) {
            $helpers[] = 'nikic/php-parser';
        }

        return $helpers;
    }

    /**
     * @param array<mixed> $manifest
     */
    private static function bundlesPhpParser(array $manifest): bool
    {
        return [] !== array_intersect(self::PHP_PARSER_PHARS, self::keys($manifest['require'] ?? null));
    }

    /**
     * @param array<mixed> $manifest
     */
    private static function requiresPhpParser(array $manifest): bool
    {
        return [] !== array_intersect(self::PHP_PARSER_HOSTS, self::keys($manifest['require'] ?? null));
    }

    /**
     * The string keys of a decoded JSON object, or none.
     *
     * @return list<string>
     */
    private static function keys(mixed $object): array
    {
        $keys = [];
        foreach (\is_array($object) ? array_keys($object) : [] as $key) {
            if (\is_string($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param list<string> $items
     */
    private static function list(array $items): string
    {
        return '['.implode(', ', array_map(self::quote(...), $items)).']';
    }

    private static function quote(string $value): string
    {
        return "'".addcslashes($value, "'\\")."'";
    }
}
