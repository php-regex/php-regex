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

namespace PHPRegex\Linter\Config;

use PHPRegex\Parser\PcreTarget;

/**
 * The lowest PHP a project installs on, as its composer.json says it:
 * config.platform.php, else the lowest version require.php allows on
 * PCRE2. Reading never fails: what cannot be read becomes a notice, and
 * the caller judges for the running PHP.
 *
 * @internal
 */
final class ComposerPlatform
{
    private function __construct() {}

    /**
     * The lowest PHP the project installs on, read from the composer.json
     * of $projectDir, or the file the COMPOSER variable names; null, with a
     * notice, when it names none.
     *
     * @param array<string, string> $env
     * @param list<string>          $notices
     *
     * @throws UnsupportedTargetException when the floor ships PCRE1 and require.php allows no PHP on PCRE2
     *
     * @return array{int, string, string|null}|null the floor, its source, and require.php when it was read
     */
    public static function read(string $projectDir, array $env, array &$notices): ?array
    {
        $name = ($env['COMPOSER'] ?? '') ?: 'composer.json';
        // A Windows drive prefix is read from the name itself: a check of
        // the file system needs no regex of the engine, which a pinned
        // limit would leave giving up on.
        $windowsDrive = \strlen($name) >= 3 && \ctype_alpha($name[0]) && ':' === $name[1]
            && ('/' === $name[2] || '\\' === $name[2]);
        $path = str_starts_with($name, '/') || $windowsDrive ? $name : $projectDir.'/'.$name;

        $contents = is_file($path) ? @file_get_contents($path) : false;
        if (false === $contents) {
            $notices[] = \sprintf('No %s in %s: judging for the running PHP.', $name, $projectDir);

            return null;
        }

        $composer = json_decode($contents, true);
        if (!\is_array($composer)) {
            $notices[] = \sprintf('%s is not a JSON object: judging for the running PHP.', $name);

            return null;
        }

        // A platform on PCRE1 pins the PHP Composer resolves the
        // dependencies for, the lowest the project installs on: it gives way
        // to a require.php that also allows a PHP on PCRE2, and is refused
        // when there is none.
        $pinned = null;
        $platform = self::at($composer, 'config', 'platform', 'php');
        if (\is_string($platform)) {
            $floor = ComposerConstraint::floor($platform);
            if (null !== $floor && !str_contains($platform, ' ') && !str_contains($platform, '|')) {
                if ($floor >= PcreTarget::phpVersionBoundaries()[0]) {
                    return [$floor, $name.' config.platform.php', null];
                }
                $pinned = $floor;
            } else {
                $notices[] = \sprintf('%s config.platform.php "%s" is not a version: reading require.php instead.', $name, $platform);
            }
        }

        $constraint = self::at($composer, 'require', 'php');
        $constraint = \is_string($constraint) ? $constraint : null;
        $intervals = null === $constraint ? null : ComposerConstraint::intervals($constraint);
        $firstOnPcre2 = null === $intervals ? null : ComposerConstraint::lowestFrom($intervals, PcreTarget::phpVersionBoundaries()[0]);
        if (null !== $pinned) {
            if (null === $firstOnPcre2) {
                throw ProjectTarget::onPcre1($pinned, $name.' config.platform.php allows');
            }
            $notices[] = \sprintf(
                '%s config.platform.php pins PHP %d.%d, and PHP 7.2 and earlier ship PCRE1, which this library does not judge: judging from PHP %s, the lowest version require.php allows on PCRE2.',
                $name,
                intdiv($pinned, 10000),
                intdiv($pinned, 100) % 100,
                ProjectTarget::phpLabel($firstOnPcre2),
            );

            return [$firstOnPcre2, $name.' require.php', $constraint];
        }

        if (null === $constraint) {
            $notices[] = \sprintf('%s has no require.php constraint: judging for the running PHP.', $name);

            return null;
        }

        if (null === $intervals) {
            $notices[] = \sprintf('%s require.php "%s" names no lowest PHP version: judging for the running PHP.', $name, $constraint);

            return null;
        }

        // A range that also allows a PHP on PCRE2 installs there too: it is
        // judged from the lowest such PHP, and only the versions on PCRE1
        // are left out. A range that allows none is refused.
        $floor = min(array_column($intervals, 0));
        if ($floor < PcreTarget::phpVersionBoundaries()[0] && null !== $firstOnPcre2) {
            $notices[] = \sprintf(
                '%s require.php allows PHP %d.%d, and PHP 7.2 and earlier ship PCRE1, which this library does not judge: judging from PHP %s, the lowest version it allows on PCRE2.',
                $name,
                intdiv($floor, 10000),
                intdiv($floor, 100) % 100,
                ProjectTarget::phpLabel($firstOnPcre2),
            );
            $floor = $firstOnPcre2;
        }

        return [ProjectTarget::onPcre2($floor, $name.' require.php allows'), $name.' require.php', $constraint];
    }

    /**
     * The value at a path of decoded JSON, or null.
     *
     * @param array<array-key, mixed> $data
     */
    private static function at(array $data, string ...$path): mixed
    {
        $value = $data;
        foreach ($path as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
