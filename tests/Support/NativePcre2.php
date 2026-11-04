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

namespace PHPRegex\Tests\Support;

/**
 * The preconditions of the native PCRE2 trace shim test: a C compiler and
 * the pcre2 headers on the host, a pinned pcre2test trace for the release
 * the shim links, and one release on both sides when the shim's outcomes
 * are compared with preg_match's. Each skip names the versions, so a cell
 * that runs nothing says why; none is ever silently green.
 */
trait NativePcre2
{
    /**
     * The directory of the pinned pcre2test traces, one file per release.
     */
    private static function pinnedTracesDirectory(): string
    {
        return __DIR__.'/../Documentation/Fixtures/pcre2-trace';
    }

    /**
     * Skip unless the host has a C compiler and pcre2-config on its PATH.
     */
    private function requiresNativePcre2(): void
    {
        foreach (['cc', 'pcre2-config'] as $tool) {
            if (null === self::nativePcre2Tool($tool)) {
                $this->markTestSkipped(\sprintf('The native trace shim needs "%s" on the PATH and this host has none (PHP %s, PCRE2 %s).', $tool, \PHP_VERSION, \PCRE_VERSION));
            }
        }
    }

    /**
     * Skip unless a pinned pcre2test trace exists for the release the shim
     * links.
     */
    private function requiresPinnedTraces(string $release): void
    {
        $file = self::pinnedTracesDirectory().'/'.$release.'.txt';
        if (!is_file($file)) {
            $this->markTestSkipped(\sprintf('No pinned pcre2test trace for PCRE2 %s (expected %s); the host PHP %s runs PCRE2 %s.', $release, $file, \PHP_VERSION, \PCRE_VERSION));
        }
    }

    /**
     * Skip unless the shim and PHP link the same PCRE2 release: outcomes
     * are compared on one engine, never across two.
     */
    private function requiresSameRelease(string $shim, string $php): void
    {
        if ($shim !== $php) {
            $this->markTestSkipped(\sprintf('The trace shim links PCRE2 %s while PHP %s runs PCRE2 %s: outcomes are compared on one release only.', $shim, \PHP_VERSION, $php));
        }
    }

    /**
     * The path of a tool on the PATH, or null.
     */
    private static function nativePcre2Tool(string $name): ?string
    {
        $path = trim((string) shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));

        return '' !== $path && is_executable($path) ? $path : null;
    }
}
