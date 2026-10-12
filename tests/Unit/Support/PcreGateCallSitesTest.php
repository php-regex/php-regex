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

namespace PHPRegex\Tests\Unit\Support;

use PHPRegex\Tests\Support\PcreGateLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The safety net around the gate trait: every call site under tests/ that
 * names its version literally stays inside the versions the suite judges,
 * so no gate is ever a permanent invisible skip.
 */
final class PcreGateCallSitesTest extends TestCase
{
    /**
     * The self-tests force their gates with versions outside every judged
     * one by design; they gate nothing in the suite.
     */
    private const SELF_TEST_PATHS = ['Unit/Support/PcreGatesTest.php', 'Unit/Support/PcreGateCallSitesTest.php'];

    #[Test]
    public function test_every_gate_call_site_in_the_suite_names_a_judged_version(): void
    {
        $callSites = $this->gateCallSites(\dirname(__DIR__, 2));

        $this->assertSame([], $this->violations($callSites));
    }

    #[Test]
    #[DataProvider('provideRejectedVersions')]
    public function test_the_checker_rejects_a_version_the_suite_does_not_judge(string $method, string $argument): void
    {
        $violations = $this->violations([[
            'file' => 'Test.php',
            'method' => $method,
            'argument' => $argument,
        ]]);

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString($argument, $violations[0]);
        $this->assertStringContainsString($method, $violations[0]);
    }

    /**
     * @return iterable<string, array{method: string, argument: string}>
     */
    public static function provideRejectedVersions(): iterable
    {
        // A release above the ceiling skips forever on every engine the
        // suite runs on: the silent direction.
        yield 'a release above the ceiling' => ['method' => 'requiresPcre2', 'argument' => '10.50'];
        yield 'a release far above the ceiling' => ['method' => 'requiresPcre2', 'argument' => '10.99'];
        yield 'a release the library never knew' => ['method' => 'requiresPcre2', 'argument' => '10.39'];
        // "10.4" reads as 10.04, a minor PCRE2 always spells with two digits.
        yield 'a misspelled minor' => ['method' => 'requiresPcre2', 'argument' => '10.4'];
        yield 'a php floor above every php' => ['method' => 'requiresPhp', 'argument' => '99.0'];
        yield 'a php the suite does not judge' => ['method' => 'requiresPhp', 'argument' => '8.6'];
        yield 'a php ceiling below every php' => ['method' => 'forbidsPhp', 'argument' => '5.0'];
        yield 'a php ceiling the suite does not judge' => ['method' => 'forbidsPhp', 'argument' => '8.6'];
    }

    #[Test]
    #[DataProvider('provideAcceptedCallSites')]
    public function test_the_checker_accepts_the_judged_boundaries(string $method, string $argument): void
    {
        $this->assertSame([], $this->violations([[
            'file' => 'Test.php',
            'method' => $method,
            'argument' => $argument,
        ]]));
    }

    /**
     * @return iterable<string, array{method: string, argument: string}>
     */
    public static function provideAcceptedCallSites(): iterable
    {
        yield 'the engine floor' => ['method' => 'requiresPcre2', 'argument' => '10.40'];
        // The ceiling itself gates on an engine that has it; it is the last
        // literal the checker may let through.
        yield 'the ceiling itself' => ['method' => 'requiresPcre2', 'argument' => '10.49'];
        yield 'the php floor the suite judges' => ['method' => 'requiresPhp', 'argument' => '8.4'];
        yield 'the php ceiling the suite judges' => ['method' => 'forbidsPhp', 'argument' => '8.5'];
        // A compile probe takes a pattern, not a version: nothing to judge.
        yield 'a compile probe pattern' => ['method' => 'requiresCompile', 'argument' => '/a{2,1}/'];
    }

    #[Test]
    public function test_the_known_releases_stop_at_the_ceiling(): void
    {
        $releases = PcreGateLimits::KNOWN_RELEASES;

        $this->assertSame(PcreGateLimits::MAX_KNOWN_RELEASE, $releases[\count($releases) - 1]);
    }

    /**
     * The gate call sites under a directory whose first argument is a
     * literal string, plus every quoted release literal in a file that also
     * feeds requiresPcre2() a computed release — the match arms and
     * ternaries those files gate from are call sites the same way, and a
     * typo in one of them skips its rows on every engine forever.
     *
     * @return list<array{file: string, method: string, argument: string}>
     */
    private function gateCallSites(string $directory): array
    {
        $callSites = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            $relative = \substr($file->getPathname(), \strlen($directory) + 1);
            if (\in_array($relative, self::SELF_TEST_PATHS, true)) {
                continue;
            }

            $source = (string) \file_get_contents($file->getPathname());

            \preg_match_all(
                "/->(requiresPcre2|requiresPhp|forbidsPhp|requiresCompile)\s*\(\s*(['\"])([^'\"]*)\\2/",
                $source,
                $matches,
                \PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                $callSites[] = [
                    'file' => $relative,
                    'method' => $match[1],
                    'argument' => $match[3],
                ];
            }

            if (1 === \preg_match('/->requiresPcre2\s*\(\s*\$/', $source)) {
                \preg_match_all("/(['\"])(10\.\d+)\\1/", $source, $releases, \PREG_SET_ORDER);

                foreach ($releases as $release) {
                    $callSites[] = [
                        'file' => $relative,
                        'method' => 'requiresPcre2',
                        'argument' => $release[2],
                    ];
                }
            }
        }

        return $callSites;
    }

    /**
     * @param list<array{file: string, method: string, argument: string}> $callSites
     *
     * @return list<string>
     */
    private function violations(array $callSites): array
    {
        $violations = [];

        foreach ($callSites as $callSite) {
            $judged = match ($callSite['method']) {
                'requiresPcre2' => PcreGateLimits::KNOWN_RELEASES,
                'requiresPhp', 'forbidsPhp' => PcreGateLimits::KNOWN_PHP_VERSIONS,
                default => null,
            };

            if (null === $judged) {
                continue;
            }

            $within = \in_array($callSite['argument'], $judged, true)
                && ('requiresPcre2' !== $callSite['method']
                    || \version_compare($callSite['argument'], PcreGateLimits::MAX_KNOWN_RELEASE, '<='));

            if (!$within) {
                $violations[] = \sprintf(
                    '%s calls %s() with "%s", which the suite does not judge.',
                    $callSite['file'],
                    $callSite['method'],
                    $callSite['argument'],
                );
            }
        }

        return $violations;
    }
}
