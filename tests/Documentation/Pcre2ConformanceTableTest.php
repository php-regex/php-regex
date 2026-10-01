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

use PHPRegex\Tests\TestUtils\Pcre2ConformanceTable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the published conformance page identical to its generator's output.
 *
 * The generated sections of docs/reference/pcre2-conformance.md (everything
 * from the generation marker to the end of the file) must be byte-identical
 * to what the table generator currently produces from the committed suite
 * and baseline fixtures. A regenerated table that was not committed, or a
 * hand edit to the generated sections, fails here.
 */
final class Pcre2ConformanceTableTest extends TestCase
{
    private const PAGE = __DIR__.'/../../docs/reference/pcre2-conformance.md';

    private const README = __DIR__.'/../../README.md';

    #[Test]
    public function test_committed_conformance_page_matches_generator_output(): void
    {
        $page = is_file(self::PAGE) ? file_get_contents(self::PAGE) : false;

        if (false === $page) {
            $this->fail(\sprintf('Missing conformance page at %s — generate it from the fixtures.', self::PAGE));
        }

        $marker = Pcre2ConformanceTable::GENERATION_MARKER;

        $this->assertStringContainsString(
            $marker,
            (string) $page,
            'the conformance page has no generation marker; the hand-written header and the generated sections drifted apart',
        );

        $position = strpos($page, $marker);
        \assert(false !== $position);

        $generated = Pcre2ConformanceTable::generate();
        $committed = substr($page, $position);

        $this->assertSame($generated, $committed, 'the committed generated sections differ from the generator output — regenerate the page');
    }

    #[Test]
    public function test_readme_bounds_hold_against_the_measured_totals(): void
    {
        $headline = self::headline(Pcre2ConformanceTable::generate());

        $this->assertSame(1, preg_match('/compile verdict agrees on \*\*(\d+) of (\d+)\*\*/', $headline, $verdict), 'the headline must state the verdict count as **X of Y**');
        $this->assertSame(1, preg_match('/\*\*(\d+)\*\* patterns PHP rejects are accepted/', $headline, $falseAccepts), 'the headline must state the false-accept count');

        // README: "more than 4,100 of the roughly 4,400 extractable cases".
        $this->assertGreaterThan(4100, (int) $verdict[1]);
        $this->assertSame(4400, (int) round((int) $verdict[2], -2), 'the extractable count no longer rounds to 4,400');
        // README: "fewer than 100" false accepts.
        $this->assertLessThan(100, (int) $falseAccepts[1]);

        $bullet = self::readmeConformanceBullet();

        $this->assertStringContainsString('more than 4,100 of the roughly 4,400', $bullet);
        $this->assertStringContainsString('fewer than 100', $bullet);
        $this->assertStringNotContainsString('offset', $bullet, 'offsets are not summarised in the README; the page has the numbers');
    }

    #[Test]
    public function test_headline_states_offset_agreement_over_all_shared_rejections(): void
    {
        // Offset agreement counts every shared rejection; agreements that
        // match only one of the two engines' offsets (10.40 and 10.48 differ
        // on that case) are counted inside it and named.
        $headline = self::headline(Pcre2ConformanceTable::generate());

        $this->assertSame(1, preg_match(
            '/error offset agrees on \*\*(\d+) of (\d+)\*\* shared rejections \(\*\*(\d+)\*\* of them match one of two version-dependent offsets\)/',
            $headline,
            $offsets,
        ), 'the headline must state offset agreement over all shared rejections and how many match one of two version-dependent offsets');

        $this->assertLessThanOrEqual((int) $offsets[2], (int) $offsets[1]);
        $this->assertLessThanOrEqual((int) $offsets[1], (int) $offsets[3]);
        $this->assertStringNotContainsString('not scored', $headline);
    }

    #[Test]
    public function test_page_header_describes_every_skip_category(): void
    {
        $page = file_get_contents(self::PAGE);
        $this->assertIsString($page);
        $position = strpos($page, Pcre2ConformanceTable::GENERATION_MARKER);
        $this->assertIsInt($position);
        $header = substr($page, 0, $position);

        foreach (['ambiguous', 'engine-skipped', 'length-limit', 'modifier-inexpressible', 'newer-than-floor', 'newline-command', 'pcre2test-api', 'php-inexpressible', 'stricter-than-floor'] as $category) {
            $this->assertStringContainsString($category, $header, \sprintf('the hand-written header does not describe the %s skip category', $category));
        }
    }

    #[Test]
    public function test_generator_refuses_a_missing_fixture(): void
    {
        $this->expectException(\RuntimeException::class);

        Pcre2ConformanceTable::generate(sys_get_temp_dir().'/pcre2-conformance-missing-'.bin2hex(random_bytes(6)).'.json', Pcre2ConformanceTable::BASELINE_PATH);
    }

    #[Test]
    public function test_generator_refuses_an_undecodable_fixture(): void
    {
        $path = sys_get_temp_dir().'/pcre2-conformance-broken-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, '{"testinput1": [');

        try {
            $this->expectException(\RuntimeException::class);

            Pcre2ConformanceTable::generate(Pcre2ConformanceTable::SUITE_PATH, $path);
        } finally {
            unlink($path);
        }
    }

    /**
     * The generated headline paragraph (the line starting "Against PCRE2").
     */
    private static function headline(string $generated): string
    {
        foreach (explode("\n", $generated) as $line) {
            if (str_starts_with($line, 'Against PCRE2')) {
                return $line;
            }
        }

        throw new \RuntimeException('The generated page has no headline.');
    }

    /**
     * The README bullet about PCRE2's official test suite, with its line
     * breaks folded into single spaces.
     */
    private static function readmeConformanceBullet(): string
    {
        $readme = file_get_contents(self::README);

        if (false === $readme || 1 !== preg_match("/^- PCRE2's own official test suite.*?(?=\n\n|\n- )/ms", $readme, $bullet)) {
            throw new \RuntimeException('README.md has no bullet about PCRE2\'s official test suite.');
        }

        return (string) preg_replace('/\s+/', ' ', $bullet[0]);
    }
}
