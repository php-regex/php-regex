<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Tests\TestUtils\Pcre2CaseRunner;

/**
 * Conformance of Regex::validate() with the pinned PCRE2 10.48 suite.
 *
 * Every extractable case of the suite (the case set comes from the committed
 * fixture only, so it is identical on every PHP version and machine) must
 * either agree with Regex::validate() or appear, with exactly the measured
 * category, in the committed conformance baseline. A new gap without a
 * baseline entry, a stale entry for a case that now passes, a category
 * drift, or a skipped case hiding in the baseline — each fails. A case whose
 * phpOverride records what PHP's own compile context does is expected to
 * behave that way.
 *
 * Unlike a fixture that silently yields no cases when its file is missing,
 * a missing suite or baseline fixture is a hard failure: this test must never
 * vacuously pass.
 *
 * @phpstan-type Pcre2Override = array{reason: string, verdict: string, offset: int|null, pcre2Code: int|null}
 * @phpstan-type Pcre2Case = array{id: string, pattern: string, delimiter: string, flags: string, verdict: string|null, offset: int|null, error: string|null, pcre2Code: int|null, phpOverride: Pcre2Override|null, floor: array{verdict: string, offset: int|null, pcre2Code: int|null}|null, skipCategory: string|null, skipReason: string|null}
 */
final class Pcre2SuiteConformanceTest extends TestCase
{
    private const SUITE_JSON = __DIR__.'/../Fixtures/Pcre2/suite-cases.json';

    private const BASELINE_JSON = __DIR__.'/../Fixtures/Pcre2/conformance-baseline.json';

    private const FILES = ['testinput1', 'testinput2', 'testinput4', 'testinput5'];

    /**
     * The defect classes a baseline entry may carry. A shared rejection is
     * compared on its body-relative offset alone, so there is no class for
     * "rejected for a different reason": that is an offset-defect.
     */
    private const DEFECT_CLASSES = ['crash', 'false-reject', 'false-accept', 'offset-defect'];

    /**
     * @var array<string, list<Pcre2Case>>|null
     */
    private static ?array $suiteCases = null;

    /**
     * @var array<string, array{category: string, libraryVerdict: string, libraryOffset: int|null}>|null
     */
    private static ?array $baseline = null;

    private static ?Pcre2CaseRunner $runner = null;

    /**
     * @param Pcre2Case $case
     */
    #[Test]
    #[DataProvider('provideSuiteCases')]
    public function test_case_agrees_with_the_pinned_pcre2_output(array $case): void
    {
        $id = $case['id'];
        $baseline = self::baseline();

        if (null !== $case['skipCategory']) {
            $this->assertArrayNotHasKey($id, $baseline, \sprintf(
                'Case %s is skipped (%s) and must not appear in the baseline.',
                $id,
                $case['skipCategory'],
            ));

            return;
        }

        $result = self::runner()->run($case);
        $outcome = $result['outcome'];
        $this->assertIsString($outcome, \sprintf('Case %s produced a non-string outcome.', $id));

        // A shared rejection whose offset differs between PCRE2 10.40 and
        // 10.48 passes when the library reports either engine's offset.
        if ('pass' === $outcome || 'pass-either-offset' === $outcome) {
            $this->assertArrayNotHasKey($id, $baseline, \sprintf(
                'Case %s passes validate() but has a baseline entry — remove the stale entry.',
                $id,
            ));

            return;
        }

        $this->assertArrayHasKey($id, $baseline, \sprintf(
            'Case %s is a new gap (%s) with no baseline entry — triage it into conformance-baseline.json.',
            $id,
            $outcome,
        ));
        $this->assertSame(
            ['category' => $outcome, 'libraryVerdict' => $result['verdict'], 'libraryOffset' => $result['offset']],
            $baseline[$id],
            \sprintf('Baseline drift for case %s — the recorded defect class, verdict or offset differs from what was measured.', $id),
        );
    }

    #[Test]
    public function test_baseline_references_real_assertable_cases(): void
    {
        $assertable = [];

        foreach (self::suiteCases() as $cases) {
            foreach ($cases as $case) {
                if (null === $case['skipCategory']) {
                    $assertable[$case['id']] = true;
                }
            }
        }

        $this->assertNotSame([], $assertable, 'the suite fixture contains no assertable cases');

        foreach (self::baseline() as $id => $entry) {
            $this->assertArrayHasKey($id, $assertable, \sprintf(
                'Baseline entry %s does not reference an assertable case of the suite (stale, or a skipped case).',
                $id,
            ));
            $this->assertSame(['category', 'libraryVerdict', 'libraryOffset'], array_keys($entry), \sprintf(
                'Baseline entry %s must hold exactly category, libraryVerdict and libraryOffset.',
                $id,
            ));
            $this->assertIsString($entry['category'], \sprintf('Baseline entry %s has no category string.', $id));
            $this->assertContains($entry['category'], self::DEFECT_CLASSES, \sprintf(
                'Baseline entry %s carries %s, which is not a defect class.',
                $id,
                $entry['category'],
            ));
        }
    }

    #[Test]
    public function test_suite_fixture_covers_all_four_vendored_files(): void
    {
        $suite = self::suiteCases();

        $this->assertSame(self::FILES, array_keys($suite), 'the suite fixture must cover exactly the four vendored testinput files');

        foreach ($suite as $file => $cases) {
            $this->assertNotSame([], $cases, \sprintf('%s contributed no cases — extractor or fixture regression', $file));
        }
    }

    /**
     * @return iterable<string, array{case: Pcre2Case}>
     */
    public static function provideSuiteCases(): iterable
    {
        foreach (self::suiteCases() as $cases) {
            foreach ($cases as $case) {
                yield $case['id'] => ['case' => $case];
            }
        }
    }

    /**
     * @return array<string, list<Pcre2Case>>
     */
    private static function suiteCases(): array
    {
        if (null !== self::$suiteCases) {
            return self::$suiteCases;
        }

        $raw = false;

        if (is_file(self::SUITE_JSON)) {
            $raw = file_get_contents(self::SUITE_JSON);
        }

        if (false === $raw) {
            throw new \RuntimeException(\sprintf(
                'Missing %s — conformance cannot be measured without the extracted case set. Regenerate it from the vendored testdata.',
                self::SUITE_JSON,
            ));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        // The top-level "meta" key records where the fixture was extracted;
        // it holds no cases.
        unset($decoded['meta']);

        if ([] === $decoded) {
            throw new \RuntimeException(\sprintf('%s is empty — conformance must not vacuously pass.', self::SUITE_JSON));
        }

        /** @var array<string, list<Pcre2Case>> $cases */
        $cases = $decoded;

        return self::$suiteCases = $cases;
    }

    /**
     * @return array<string, array{category: string, libraryVerdict: string, libraryOffset: int|null}>
     */
    private static function baseline(): array
    {
        if (null !== self::$baseline) {
            return self::$baseline;
        }

        $raw = false;

        if (is_file(self::BASELINE_JSON)) {
            $raw = file_get_contents(self::BASELINE_JSON);
        }

        if (false === $raw) {
            throw new \RuntimeException(\sprintf(
                'Missing %s — run the first-pass triage and commit the baseline before conformance can be asserted.',
                self::BASELINE_JSON,
            ));
        }

        /** @var array<string, array{category: string, libraryVerdict: string, libraryOffset: int|null}> $decoded */
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return self::$baseline = $decoded;
    }

    private static function runner(): Pcre2CaseRunner
    {
        return self::$runner ??= new Pcre2CaseRunner();
    }
}
