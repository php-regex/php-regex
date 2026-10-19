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

namespace PHPRegex\Tests\Integration;

use PHPRegex\Tests\TestUtils\Pcre2CaseRunner;
use PHPRegex\Tests\TestUtils\Pcre2ReleaseOutcomes;
use PHPRegex\Tests\TestUtils\PcreMessageCodes;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The library judged for each PCRE2 release from 10.32 to 10.49 agrees with
 * what that release does with every case of the releases' own test suites
 * on which they do not all agree: the verdict, the offset of a refusal, and
 * its code, which must be one the error PCRE2 reports allows. Each release
 * is judged for PHP 8.4, and 10.32 to 10.35 once more for PHP 7.3 and 7.4.
 *
 * The outcomes come from tests/Fixtures/Pcre2/release-outcomes.json, built
 * from the release tarballs by tests/Tools/extract_pcre2_release_outcomes.php;
 * a missing fixture, or one edited without regenerating, fails here.
 *
 * @phpstan-import-type ReleaseCase from Pcre2ReleaseOutcomes
 */
final class Pcre2ReleaseOutcomesTest extends TestCase
{
    /**
     * @var array{meta: array{releases: list<string>, php: int, php7: array<string, int>, messages: array<string, array<string, string>>, crossChecked: string}, cases: list<ReleaseCase>}|null
     */
    private static ?array $fixture = null;

    /**
     * @var array<string, Regex>
     */
    private static array $regexes = [];

    #[Test]
    public function test_the_fixture_covers_the_releases_the_tool_measures(): void
    {
        $meta = self::fixture()['meta'];

        $this->assertSame(Pcre2ReleaseOutcomes::RELEASES, $meta['releases']);
        $this->assertSame(Pcre2ReleaseOutcomes::PHP, $meta['php']);
        $this->assertSame(Pcre2ReleaseOutcomes::PHP7, $meta['php7']);
        $this->assertGreaterThan(1000, \count(self::fixture()['cases']));
    }

    #[Test]
    public function test_the_fixture_was_not_edited_by_hand(): void
    {
        $fixture = self::fixture();

        $this->assertSame($fixture['meta']['crossChecked'], Pcre2ReleaseOutcomes::checksum($fixture['cases']), 'release-outcomes.json was edited without regenerating it: run tests/Tools/extract_pcre2_release_outcomes.php.');
        $this->assertSame(Pcre2ReleaseOutcomes::encode($fixture), (string) file_get_contents(Pcre2ReleaseOutcomes::FIXTURE));
    }

    /**
     * A code is held to the error PCRE2 reports: an error the message table
     * does not know would leave the code unchecked.
     */
    #[Test]
    public function test_every_error_the_releases_report_names_its_codes(): void
    {
        $unknown = [];
        foreach (self::fixture()['meta']['messages'] as $number => $wordings) {
            foreach ($wordings as $release => $message) {
                if (!isset(PcreMessageCodes::CODES[$message])) {
                    $unknown[] = \sprintf('%s (error %s from %s)', $message, $number, $release);
                }
            }
        }

        $this->assertSame([], $unknown);
    }

    /**
     * @param ReleaseCase $case
     */
    #[Test]
    #[DataProvider('provideCases')]
    public function test_the_library_judges_each_release_as_it_compiles(array $case): void
    {
        $meta = self::fixture()['meta'];
        $pattern = (new Pcre2CaseRunner())->phpPattern($case);
        $disagreements = [];

        foreach (Pcre2ReleaseOutcomes::expand($case['php'], $meta['releases']) as $release => $expected) {
            $disagreements[] = $this->disagreement($pattern, $meta['php'], $release, $expected);
        }

        $php7 = $case['php7'] ?? null;
        if (false !== $php7) {
            $legacy = null === $php7 ? Pcre2ReleaseOutcomes::expand($case['php'], $meta['releases']) : Pcre2ReleaseOutcomes::expand($php7, array_keys($meta['php7']));
            foreach ($meta['php7'] as $release => $phpVersion) {
                if (\array_key_exists($release, $legacy)) {
                    $disagreements[] = $this->disagreement($pattern, $phpVersion, $release, $legacy[$release]);
                }
            }
        }

        $this->assertSame([], array_values(array_filter($disagreements)), $case['id'].' '.$pattern);
    }

    /**
     * @return iterable<string, array{ReleaseCase}>
     */
    public static function provideCases(): iterable
    {
        foreach (self::fixture()['cases'] as $case) {
            yield $case['id'] => [$case];
        }
    }

    /**
     * What the library says against what the release does, or null when
     * they agree.
     *
     * @param array{0: int, 1: int}|null $expected
     */
    private function disagreement(string $pattern, int $phpVersion, string $release, ?array $expected): ?string
    {
        $result = (self::$regexes[$phpVersion.'/'.$release] ??= Regex::create([
            'php_version' => $phpVersion,
            'pcre_version' => $release,
            'runtime_pcre_validation' => false,
            'cache' => null,
        ]))->validate($pattern);

        $context = \sprintf('PHP %d, PCRE2 %s: ', $phpVersion, $release);
        if (null === $expected) {
            return $result->isValid ? null : $context.'refused at '.($result->offset ?? '?').' ('.($result->error ?? '').'), where PCRE2 compiles it';
        }

        [$offset, $number] = $expected;
        if ($result->isValid) {
            return $context.\sprintf('accepted, where PCRE2 refuses it at %d with error %d', $offset, $number);
        }

        if ($offset !== $result->offset) {
            return $context.\sprintf('refused at %s, where PCRE2 refuses it at %d with error %d', $result->offset ?? '?', $offset, $number);
        }

        $message = self::message($number, $release);
        $codes = PcreMessageCodes::CODES[$message] ?? [];
        if ([] !== $codes && !\in_array($result->errorCode?->value, $codes, true)) {
            return $context.\sprintf('code %s, where PCRE2 reports "%s" (%s)', $result->errorCode->value ?? 'none', $message, implode(', ', $codes));
        }

        return null;
    }

    private static function message(int $number, string $release): string
    {
        $wordings = self::fixture()['meta']['messages'][(string) $number] ?? [];
        $message = '';
        foreach (Pcre2ReleaseOutcomes::expand($wordings, self::fixture()['meta']['releases']) as $at => $wording) {
            if (Pcre2ReleaseOutcomes::number($at) <= Pcre2ReleaseOutcomes::number($release)) {
                $message = $wording;
            }
        }

        return $message;
    }

    /**
     * @return array{meta: array{releases: list<string>, php: int, php7: array<string, int>, messages: array<string, array<string, string>>, crossChecked: string}, cases: list<ReleaseCase>}
     */
    private static function fixture(): array
    {
        if (null === self::$fixture) {
            $json = file_get_contents(Pcre2ReleaseOutcomes::FIXTURE);
            self::assertIsString($json, 'The release outcomes fixture is missing.');
            /** @var array{meta: array{releases: list<string>, php: int, php7: array<string, int>, messages: array<string, array<string, string>>, crossChecked: string}, cases: list<ReleaseCase>} $decoded */
            $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
            self::$fixture = $decoded;
        }

        return self::$fixture;
    }
}
