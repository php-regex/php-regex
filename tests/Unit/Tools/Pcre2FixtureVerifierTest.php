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

use PHPRegex\Tests\TestUtils\Pcre2FixtureVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the fixture verifier: every floor observation in the
 * committed suite fixture is re-observed on the PCRE2 10.40 pcre2test, and
 * every assertable case on the 10.48 pcre2test under PHP's compile context;
 * any difference is reported with the case id.
 *
 * The observers here are fakes that return what the real binaries print for
 * these patterns under PHP's compile context (checked with both builds):
 * "[abc" error 106 at 4 on both, "abc" compiles on both, "(?=a\Kb)ab"
 * compiles on both with allow_lookaround_bsk, "\x{ 41 }" is error 167 at 3 on
 * 10.40 and compiles on 10.48, "\xthing" compiles on 10.40 and is error 178
 * at 2 on 10.48.
 *
 * @phpstan-type Observation = array{verdict: string, offset: int|null, pcre2Code: int|null}
 */
final class Pcre2FixtureVerifierTest extends TestCase
{
    /**
     * Case ids each fake observer was asked about, in call order.
     *
     * @var array{floor: list<string>, pin: list<string>}
     */
    private array $calls = ['floor' => [], 'pin' => []];

    protected function setUp(): void
    {
        $this->calls = ['floor' => [], 'pin' => []];
    }

    #[Test]
    public function test_clean_fixture_has_no_mismatch(): void
    {
        $this->assertSame([], $this->verify(self::fixture()));
    }

    #[Test]
    public function test_floor_is_re_observed_for_every_row_carrying_one_and_pin_for_every_engine_backed_row(): void
    {
        $this->verify(self::fixture());

        // sample:9 (newer-than-floor) and sample:13 (stricter-than-floor) carry
        // a floor observation and are not assertable, yet the pin must confirm
        // the verdict change that skipped them; sample:11 is skipped without
        // one and is never observed.
        $this->assertSame(['sample:1', 'sample:3', 'sample:5', 'sample:9', 'sample:13'], $this->calls['floor']);
        $this->assertSame(['sample:1', 'sample:3', 'sample:5', 'sample:9', 'sample:13'], $this->calls['pin']);
    }

    #[Test]
    public function test_newer_than_floor_row_the_pin_rejects_is_reported(): void
    {
        $mismatches = $this->verify(self::fixture(), ['sample:9' => ['verdict' => 'reject', 'offset' => 3, 'pcre2Code' => 167]]);

        $this->assertCount(1, $mismatches);
        $this->assertStringContainsString('sample:9', $mismatches[0]);
    }

    #[Test]
    public function test_stricter_than_floor_row_the_pin_compiles_is_reported(): void
    {
        $mismatches = $this->verify(self::fixture(), ['sample:13' => ['verdict' => 'accept', 'offset' => null, 'pcre2Code' => null]]);

        $this->assertCount(1, $mismatches);
        $this->assertStringContainsString('sample:13', $mismatches[0]);
    }

    #[Test]
    public function test_forged_floor_observation_is_reported(): void
    {
        $fixture = self::fixture();
        $fixture['testinput1'][0]['floor'] = ['verdict' => 'reject', 'offset' => 5, 'pcre2Code' => 106];

        $mismatches = $this->verify($fixture);

        $this->assertCount(1, $mismatches);
        $this->assertStringContainsString('sample:1', $mismatches[0]);
    }

    #[Test]
    public function test_forged_pin_verdict_is_reported(): void
    {
        $fixture = self::fixture();
        $fixture['testinput1'][1]['verdict'] = 'reject';
        $fixture['testinput1'][1]['offset'] = 0;
        $fixture['testinput1'][1]['pcre2Code'] = 106;

        $mismatches = $this->verify($fixture);

        $this->assertCount(1, $mismatches);
        $this->assertStringContainsString('sample:3', $mismatches[0]);
    }

    #[Test]
    public function test_forged_pin_offset_is_reported(): void
    {
        $fixture = self::fixture();
        $fixture['testinput1'][0]['offset'] = 5;

        $mismatches = $this->verify($fixture);

        $this->assertCount(1, $mismatches);
        $this->assertStringContainsString('sample:1', $mismatches[0]);
    }

    #[Test]
    public function test_forged_pin_error_number_is_reported(): void
    {
        $fixture = self::fixture();
        $fixture['testinput1'][0]['pcre2Code'] = 107;

        $mismatches = $this->verify($fixture);

        $this->assertCount(1, $mismatches);
        $this->assertStringContainsString('sample:1', $mismatches[0]);
    }

    #[Test]
    public function test_php_override_is_checked_against_the_pin_observation(): void
    {
        // sample:5 keeps the suite's record (error 199 at 10) and carries the
        // override PHP produced; the pin observation under PHP's compile
        // context compiles it, so only a forged override is a mismatch.
        $fixture = self::fixture();
        $fixture['testinput1'][2]['phpOverride'] = ['reason' => 'allow-lookaround-bsk', 'verdict' => 'reject', 'offset' => 10, 'pcre2Code' => null];

        $mismatches = $this->verify($fixture);

        $this->assertCount(1, $mismatches);
        $this->assertStringContainsString('sample:5', $mismatches[0]);
    }

    /**
     * Runs the verifier with fake observers that record each call and
     * return the real engines' observations by case id.
     *
     * @param array<string, mixed>       $fixture
     * @param array<string, Observation> $pinOverrides forged pin observations, by case id
     *
     * @return list<string>
     */
    private function verify(array $fixture, array $pinOverrides = []): array
    {
        $floor = [
            'sample:1' => ['verdict' => 'reject', 'offset' => 4, 'pcre2Code' => 106],
            'sample:3' => ['verdict' => 'accept', 'offset' => null, 'pcre2Code' => null],
            'sample:5' => ['verdict' => 'accept', 'offset' => null, 'pcre2Code' => null],
            'sample:9' => ['verdict' => 'reject', 'offset' => 3, 'pcre2Code' => 167],
            'sample:13' => ['verdict' => 'accept', 'offset' => null, 'pcre2Code' => null],
        ];
        $pin = [
            'sample:1' => ['verdict' => 'reject', 'offset' => 4, 'pcre2Code' => 106],
            'sample:3' => ['verdict' => 'accept', 'offset' => null, 'pcre2Code' => null],
            'sample:5' => ['verdict' => 'accept', 'offset' => null, 'pcre2Code' => null],
            'sample:9' => ['verdict' => 'accept', 'offset' => null, 'pcre2Code' => null],
            'sample:13' => ['verdict' => 'reject', 'offset' => 2, 'pcre2Code' => 178],
        ];

        return Pcre2FixtureVerifier::verify($fixture, $this->observer('floor', $floor), $this->observer('pin', array_replace($pin, $pinOverrides)));
    }

    /**
     * A fake observer that records the case ids it is asked about and
     * returns the recorded observation of each.
     *
     * @param 'floor'|'pin'              $engine
     * @param array<string, Observation> $observations
     *
     * @return \Closure(array<string, mixed>): Observation
     */
    private function observer(string $engine, array $observations): \Closure
    {
        return function (array $row) use ($engine, $observations): array {
            $id = \is_string($row['id'] ?? null) ? $row['id'] : '';
            $this->calls[$engine][] = $id;

            if (!isset($observations[$id])) {
                throw new \LogicException(\sprintf('No %s observation recorded for %s.', $engine, $id));
            }

            return $observations[$id];
        };
    }

    /**
     * A committed-fixture shape with one row per situation: an agreed
     * rejection, an agreed accept, a phpOverride row, a newer-than-floor
     * row, a row skipped before any engine ran, and a stricter-than-floor
     * row.
     *
     * @return array{meta: array<string, string>, testinput1: list<array<string, mixed>>}
     */
    private static function fixture(): array
    {
        $row = static fn (int $line, string $pattern, ?string $verdict, ?int $offset, ?int $code, ?array $override, ?array $floor, ?string $skip): array => [
            'id' => 'sample:'.$line,
            'pattern' => $pattern,
            'delimiter' => '/',
            'flags' => '',
            'verdict' => $verdict,
            'offset' => $offset,
            'error' => null,
            'pcre2Code' => $code,
            'phpOverride' => $override,
            'floor' => $floor,
            'skipCategory' => $skip,
            'skipReason' => null === $skip ? null : 'skipped for the test',
        ];

        return [
            'meta' => ['pin' => '10.48', 'phpVersion' => '8.4.26', 'pcreVersion' => '10.48 2026-08-31', 'floorVersion' => '10.40', 'crossChecked' => 'not checked here'],
            'testinput1' => [
                $row(1, '[abc', 'reject', 4, 106, null, ['verdict' => 'reject', 'offset' => 4, 'pcre2Code' => 106], null),
                $row(3, 'abc', 'accept', null, null, null, ['verdict' => 'accept', 'offset' => null, 'pcre2Code' => null], null),
                $row(5, '(?=a\Kb)ab', 'reject', 10, 199, ['reason' => 'allow-lookaround-bsk', 'verdict' => 'accept', 'offset' => null, 'pcre2Code' => null], ['verdict' => 'accept', 'offset' => null, 'pcre2Code' => null], null),
                $row(9, '\x{ 41 }', null, null, null, null, ['verdict' => 'reject', 'offset' => 3, 'pcre2Code' => 167], 'newer-than-floor'),
                $row(11, '61 62', null, null, null, null, null, 'pcre2test-api'),
                $row(13, '\xthing', null, null, null, null, ['verdict' => 'accept', 'offset' => null, 'pcre2Code' => null], 'stricter-than-floor'),
            ],
        ];
    }
}
