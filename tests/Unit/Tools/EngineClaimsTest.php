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

namespace PHPRegex\Tests\Unit\Tools;

use PHPRegex\Linter\Internal\SilentMeaningChange;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Tests\TestUtils\EngineClaims;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The engine claims fixture is what the library claims today: a verdict, a
 * reading or a rewrite that moves leaves the fixture stale until it is
 * written again, and the engines CI runs it on are the ones it names.
 */
final class EngineClaimsTest extends TestCase
{
    #[Test]
    public function test_the_fixture_holds_what_the_library_claims_today(): void
    {
        $this->assertSame(
            EngineClaims::encode(EngineClaims::build()),
            (string) file_get_contents(EngineClaims::FIXTURE),
            'The engine claims moved: run "php tests/Tools/write_engine_claims.php" and commit the fixture.',
        );
    }

    #[Test]
    public function test_the_fixture_is_json_the_verifier_reads(): void
    {
        $fixture = json_decode((string) file_get_contents(EngineClaims::FIXTURE), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertIsArray($fixture);
        $this->assertSame(EngineClaims::releases(), $fixture['releases']);
        $this->assertIsArray($fixture['claims']);
        $this->assertNotEmpty($fixture['claims']);
    }

    /**
     * Each change of meaning the lint words is a match claim of the
     * fixture, with the witness the lint gives.
     */
    #[Test]
    public function test_each_change_of_meaning_the_lint_words_has_its_match_claim(): void
    {
        $patterns = [];
        foreach (EngineClaims::build() as $claim) {
            if (isset($claim['match']) && \is_string($claim['pattern'])) {
                $patterns[] = $claim['pattern'];
            }
        }

        $unclaimed = [];
        foreach ((new \ReflectionClass(SilentMeaningChange::class))->getConstants() as $name => $message) {
            if (!\is_string($message) || 1 !== preg_match('~(/\S+/[a-z]*) (?:finds|captures|matches) ~', $message, $witness)) {
                continue;
            }
            if (!\in_array($witness[1], $patterns, true)) {
                $unclaimed[] = $name;
            }
        }

        $this->assertSame([], $unclaimed);
    }

    #[Test]
    public function test_a_witness_the_lint_does_not_report_is_refused(): void
    {
        $this->assertTrue(EngineClaims::lintReports(PcreFeature::PossessiveRepeatOnceIsAtomic, ['pattern' => '/(a|ab){1}+c/', 'subject' => 'abc', 'group' => 0, 'before' => 'abc', 'from' => null]));
        $this->assertFalse(EngineClaims::lintReports(PcreFeature::PossessiveRepeatOnceIsAtomic, ['pattern' => '/(a|ab){1}c/', 'subject' => 'abc', 'group' => 0, 'before' => 'abc', 'from' => 'abc']));
        $this->assertFalse(EngineClaims::lintReports(PcreFeature::GroupHoldingItsOwnReferenceBacktracks, ['pattern' => '/(a|ab){1}+c/', 'subject' => 'abc', 'group' => 0, 'before' => 'abc', 'from' => null]));
    }

    /**
     * @return iterable<string, array{pattern: string, phpVersionId: int, release: string, verdict: bool|int|string}>
     */
    public static function provideVerdicts(): iterable
    {
        yield 'compiles' => ['pattern' => '/a/', 'phpVersionId' => 80400, 'release' => '10.44', 'verdict' => true];
        yield 'refused at an offset' => ['pattern' => '/(*pla:a)a/', 'phpVersionId' => 70300, 'release' => '10.32', 'verdict' => 5];
        yield 'accepted without a warning by PHP 7.3' => ['pattern' => '/\p{Bogus}/u', 'phpVersionId' => 70300, 'release' => '10.32', 'verdict' => 'silent'];
        yield 'warned of by PHP 8.0' => ['pattern' => '/\p{Bogus}/u', 'phpVersionId' => 80000, 'release' => '10.35', 'verdict' => 9];
    }

    #[Test]
    #[DataProvider('provideVerdicts')]
    public function test_a_verdict_is_what_the_validator_says_for_the_target(string $pattern, int $phpVersionId, string $release, bool|int|string $verdict): void
    {
        $this->assertSame($verdict, EngineClaims::verdict($pattern, $phpVersionId, $release));
    }

    /**
     * The engine-claims job runs one cell per image the fixture names, each
     * checked to run the PCRE2 the fixture expects of it.
     */
    #[Test]
    public function test_the_job_runs_every_image_the_fixture_names(): void
    {
        $workflow = (string) file_get_contents(\dirname(__DIR__, 3).'/.github/workflows/engines.yml');
        $this->assertSame(1, preg_match('~\n  engine-claims:\n(.*?)(?=\n  [\w-]+:\n|\z)~s', $workflow, $job), 'engines.yml has an engine-claims job.');
        preg_match_all("~- \\{ php: '([^']+)', pcre: '([^']+)' \\}~", $job[1], $rows, \PREG_SET_ORDER);

        $cells = [];
        foreach ($rows as $row) {
            $cells[$row[1]] = $row[2];
        }

        $this->assertSame(EngineClaims::IMAGES, $cells);
    }

    #[Test]
    public function test_a_string_that_is_no_utf8_is_written_in_hex(): void
    {
        $fixture = EngineClaims::encode([['source' => 'test', 'pattern' => "/\xff/", 'subject' => "\u{30FB}", 'group' => 0, 'match' => ['10.44' => null]]]);

        $this->assertStringContainsString('"pattern":{"hex":"2fff2f"}', $fixture);
        $this->assertStringContainsString("\"subject\":\"\u{30FB}\"", $fixture);
    }
}
