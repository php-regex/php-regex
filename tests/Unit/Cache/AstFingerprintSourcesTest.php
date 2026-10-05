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

use PHPRegex\Parser\AbstractNodeVisitor;
use PHPRegex\Parser\Analysis\ComplexityScorer;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Internal\StaticCaches;
use PHPRegex\Parser\NodeVisitorInterface;
use PHPRegex\Parser\Validation\ValidationErrorCategory;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Tests\Support\AstFingerprint;
use PHPUnit\Framework\Attributes\DataProvider;
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
    ];

    /**
     * Library code a fingerprinted file imports without it deciding what a
     * tree or an automaton holds, by prefix of the class name.
     */
    private const IMPORTS_WITHOUT_BEHAVIOUR = [
        // Exceptions and their codes: what is refused is decided elsewhere.
        'PHPRegex\\Automata\\Exception\\',
        'PHPRegex\\Parser\\Exception\\',
        ErrorCode::class,
        // The visitor contract and its empty base.
        NodeVisitorInterface::class,
        AbstractNodeVisitor::class,
        // Empties the process-wide caches.
        StaticCaches::class,
        // Explores two automata for a witness, never stored in a cache.
        'PHPRegex\\Automata\\Match\\',
        // The store the trees go to, not what they hold.
        'PHPRegex\\Parser\\Cache\\',
        // What validate() returns is worked out on each call, never cached:
        // the result, its category and the complexity score.
        ValidationResult::class,
        ValidationErrorCategory::class,
        ComplexityScorer::class,
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

    /**
     * What a fingerprinted file imports from the library decides what it
     * builds too, unless it is listed as having no say in it: options read
     * from the start of the pattern shape the DFA, the length calculator
     * what validation refuses.
     */
    #[Test]
    public function test_every_library_class_a_fingerprinted_file_imports_is_fingerprinted(): void
    {
        $root = AstFingerprint::root();
        $fingerprinted = array_map(static fn (string $file): string => substr($file, \strlen($root) + 1), AstFingerprint::files());

        $missing = [];
        foreach ($fingerprinted as $file) {
            preg_match_all('/^use (PHPRegex\\\\[\\w\\\\]+)(?: as \\w+)?;/m', (string) file_get_contents($root.'/'.$file), $imports);
            foreach ($imports[1] as $class) {
                $path = 'src/'.str_replace('\\', '/', substr($class, \strlen('PHPRegex\\'))).'.php';
                if (\in_array($path, $fingerprinted, true)) {
                    continue;
                }

                foreach (self::IMPORTS_WITHOUT_BEHAVIOUR as $prefix) {
                    if (str_starts_with($class, $prefix)) {
                        continue 2;
                    }
                }

                $missing[$path][] = $file;
            }
        }
        ksort($missing);

        $this->assertSame([], array_keys($missing), 'Add these files to AstFingerprint::SOURCES and run "task cache-version".');
    }

    /**
     * A persistent DFA cache is keyed on the cache version: every file of
     * the code that turns a tree into a DFA is fingerprinted, the HIR, the
     * NFA built from it, its determinization and minimization, the model
     * they share and the Unicode helpers, so a change there drops the DFAs
     * an older build stored.
     */
    #[Test]
    #[DataProvider('provideDfaBuildingDirectories')]
    public function test_every_file_of_the_code_that_builds_a_dfa_is_fingerprinted(string $directory): void
    {
        $root = AstFingerprint::root();
        $this->assertDirectoryExists($root.'/'.$directory);

        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        $this->assertNotSame([], $files);

        $missing = array_values(array_diff($files, AstFingerprint::files()));

        $this->assertSame([], array_map(static fn (string $file): string => substr($file, \strlen($root) + 1), $missing), 'Add these files to AstFingerprint::SOURCES and run "task cache-version".');
    }

    /**
     * @return iterable<string, array{directory: string}>
     */
    public static function provideDfaBuildingDirectories(): iterable
    {
        yield 'NFA and DFA builders' => ['directory' => 'src/Automata/Builder'];
        yield 'determinization' => ['directory' => 'src/Automata/Determinization'];
        yield 'minimization' => ['directory' => 'src/Automata/Minimization'];
        yield 'automaton model' => ['directory' => 'src/Automata/Model'];
        yield 'unicode helpers' => ['directory' => 'src/Automata/Unicode'];
        // Fingerprinted already: kept as guards.
        yield 'tree to NFA' => ['directory' => 'src/Automata/Transform'];
        yield 'HIR' => ['directory' => 'src/Parser/Hir'];
    }

    #[Test]
    public function test_the_fingerprint_changes_with_the_delimiter_trimming(): void
    {
        $this->assertContains(AstFingerprint::root().'/src/Parser/Internal/Ascii.php', AstFingerprint::files());
        $this->assertContains(AstFingerprint::root().'/src/Parser/Internal/PatternParser.php', AstFingerprint::files());
    }
}
