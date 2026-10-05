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

namespace PHPRegex\Tests\Unit\Cache;

use PHPRegex\Tests\Support\AstFingerprint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The cache version is the fingerprint of the code that builds a tree, so a
 * helper that code calls is part of it: an edit to the helper changes the
 * trees without changing the version, and a stale tree comes back from the
 * cache.
 */
final class AstFingerprintSourcesTest extends TestCase
{
    /**
     * Internal helpers the fingerprinted code calls without them deciding
     * what a tree holds.
     */
    private const NOT_TREE_BUILDING = [
        // Escapes the text of an error message.
        'DisplayEscaper',
        // Empties the process-wide caches.
        'StaticCaches',
        // Read by RegexNode when asked, from the source the tree keeps.
        'StartOptions',
    ];

    #[Test]
    public function test_every_internal_helper_the_parser_calls_is_fingerprinted(): void
    {
        $root = AstFingerprint::root();
        $fingerprinted = array_map(static fn (string $file): string => substr($file, \strlen($root) + 1), AstFingerprint::files());

        $helpers = [];
        foreach ((array) glob($root.'/src/Parser/Internal/*.php') as $file) {
            $helpers[basename((string) $file, '.php')] = 'src/Parser/Internal/'.basename((string) $file);
        }
        $this->assertArrayHasKey('Ascii', $helpers);

        $missing = [];
        foreach (AstFingerprint::files() as $file) {
            $code = (string) file_get_contents($file);
            foreach ($helpers as $class => $path) {
                if (\in_array($class, self::NOT_TREE_BUILDING, true) || \in_array($path, $fingerprinted, true)) {
                    continue;
                }

                if (1 === preg_match('/(?:\\\\Internal\\\\|\b)'.$class.'::/', $code)) {
                    $missing[$path][] = substr($file, \strlen($root) + 1);
                }
            }
        }

        $this->assertSame([], $missing, 'Add these files to AstFingerprint::SOURCES and run "task cache-version".');
    }

    #[Test]
    public function test_the_fingerprint_changes_with_the_delimiter_trimming(): void
    {
        $this->assertContains(AstFingerprint::root().'/src/Parser/Internal/Ascii.php', AstFingerprint::files());
        $this->assertContains(AstFingerprint::root().'/src/Parser/Internal/PatternParser.php', AstFingerprint::files());
    }
}
