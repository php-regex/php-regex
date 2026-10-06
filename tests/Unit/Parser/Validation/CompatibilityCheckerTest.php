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

namespace PHPRegex\Tests\Unit\Parser\Validation;

use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\CompatibilityChecker;
use PHPRegex\Parser\Validation\PatternCompatibility;
use PHPRegex\Parser\Validation\TargetVerdict;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Tests\TestUtils\PhpErrorOffset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * On which PHP and PCRE2 a pattern is valid: the validator, run at every
 * point of the matrix (the PHP versions a rule changes at, by the PCRE2
 * releases the library knows), never a second table. Validity is not
 * monotone: \K in a lookaround is refused from PHP 8.5 on.
 *
 * The verdict that judges the running engine (its PHP minor at its patch
 * point, its PCRE2 release or, past the newest the library knows, the
 * newest) is checked against preg_match() itself.
 */
final class CompatibilityCheckerTest extends TestCase
{
    /**
     * The PHP versions a rule changes at: the 8.2 floor, the PCRE2 that 8.3
     * and 8.4 bundle, "r" (8.4), \C under "u" (8.4.25, 8.5.10), \K in a
     * lookaround (8.5).
     */
    private const PHP_POINTS = [80200, 80300, 80400, 80425, 80500, 80510];

    #[Test]
    public function test_verdicts_run_every_php_point_then_every_pcre2_release(): void
    {
        $expected = [];
        foreach (self::PHP_POINTS as $php) {
            foreach (self::releases() as $release) {
                $expected[] = [$php, $release];
            }
        }

        $verdicts = (new CompatibilityChecker())->check('/a/')->verdicts();

        $this->assertContainsOnlyInstancesOf(TargetVerdict::class, $verdicts);
        $this->assertSame($expected, array_map(
            static fn (TargetVerdict $verdict): array => [$verdict->target->phpVersionId, $verdict->target->pcreVersion],
            $verdicts,
        ));
    }

    #[Test]
    public function test_pcre2_axis_ends_at_the_newest_release_the_library_knows(): void
    {
        $verdicts = (new CompatibilityChecker())->check('/a/')->verdicts();
        $releases = array_values(array_unique(array_map(static fn (TargetVerdict $verdict): string => $verdict->target->pcreVersion, $verdicts)));

        $this->assertSame(self::releases(), $releases);
    }

    /**
     * @param \Closure(int, int): bool $valid     by PHP_VERSION_ID and PCRE2 minor release
     * @param string|null              $errorCode the code of every invalid verdict
     */
    #[Test]
    #[DataProvider('provideRows')]
    public function test_each_verdict_is_the_validator_answer_at_its_target(string $regex, \Closure $valid, ?string $errorCode): void
    {
        $compatibility = (new CompatibilityChecker())->check($regex);

        foreach ($compatibility->verdicts() as $verdict) {
            $target = $verdict->target;
            $label = \sprintf('PHP %d, PCRE2 %s', $target->phpVersionId, $target->pcreVersion);

            $this->assertInstanceOf(PcreTarget::class, $target);
            $this->assertInstanceOf(ValidationResult::class, $verdict->validation);
            $this->assertSame($valid($target->phpVersionId, self::minor($target->pcreVersion)), $verdict->validation->isValid, $label);
            if (!$verdict->validation->isValid) {
                $this->assertSame($errorCode, $verdict->validation->errorCode?->value, $label);
            }
            $this->assertEquals(self::validate($regex, $target), $verdict->validation, $label);
        }
    }

    /**
     * @param \Closure(int, int): bool $valid
     */
    #[Test]
    #[DataProvider('provideRows')]
    public function test_invalid_verdicts_and_valid_everywhere_follow_the_verdicts(string $regex, \Closure $valid, ?string $errorCode): void
    {
        $compatibility = (new CompatibilityChecker())->check($regex);

        $invalid = array_values(array_filter(
            $compatibility->verdicts(),
            static fn (TargetVerdict $verdict): bool => !$verdict->validation->isValid,
        ));

        $this->assertEquals($invalid, $compatibility->invalidVerdicts());
        foreach ($compatibility->invalidVerdicts() as $verdict) {
            $this->assertSame($errorCode, $verdict->validation->errorCode?->value);
        }
        $this->assertSame([] === $invalid, $compatibility->isValidEverywhere());

        $everywhere = true;
        foreach (self::PHP_POINTS as $php) {
            foreach (self::releases() as $release) {
                $everywhere = $everywhere && $valid($php, self::minor($release));
            }
        }
        $this->assertSame($everywhere, $compatibility->isValidEverywhere());
    }

    /**
     * Each row grounded in preg_match() on PHP 8.4.26 / PCRE2 10.49 where
     * that engine is the judge, and in PHP's sources elsewhere: "r" needs PHP
     * 8.4 built on PCRE2 10.43; PHP 8.5 compiles without
     * PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK (pcre2test 10.49 without it: error
     * 199); "{,3}" repeats from PCRE2 10.43 (pcre2test 10.49: error 109,
     * quantifier does not follow a repeatable item).
     *
     * @return iterable<string, array{regex: string, valid: \Closure(int, int): bool, errorCode: string|null}>
     */
    public static function provideRows(): iterable
    {
        yield 'n modifier, valid from the 8.2 floor' => [
            'regex' => '/(a)/n',
            'valid' => static fn (int $php, int $pcre): bool => true,
            'errorCode' => null,
        ];
        yield 'valid everywhere' => [
            'regex' => '/[a-z]+\d*/i',
            'valid' => static fn (int $php, int $pcre): bool => true,
            'errorCode' => null,
        ];
        yield 'open repeat count that matches text before 10.43, still valid' => [
            'regex' => '/a{,3}/',
            'valid' => static fn (int $php, int $pcre): bool => true,
            'errorCode' => null,
        ];
        yield 'r modifier: PHP 8.4 with PCRE2 10.43' => [
            'regex' => '/a/r',
            'valid' => static fn (int $php, int $pcre): bool => $php >= 80400 && $pcre >= 43,
            'errorCode' => 'regex.flag.unknown',
        ];
        yield '\K in a lookbehind, refused from PHP 8.5' => [
            'regex' => '/(?<=a\Kb)c/',
            'valid' => static fn (int $php, int $pcre): bool => $php < 80500,
            'errorCode' => 'regex.keep.in_lookaround',
        ];
        yield '\K in a lookahead, refused from PHP 8.5' => [
            'regex' => '/(?=a\K)a/',
            'valid' => static fn (int $php, int $pcre): bool => $php < 80500,
            'errorCode' => 'regex.keep.in_lookaround',
        ];
        // The library refuses \C under "u" on every PHP: a PHP that compiles
        // it can crash matching it. The 8.4.25 and 8.5.10 gates move where
        // the error is reported (see the offset test below).
        yield '\C under u in a lookbehind' => [
            'regex' => '/(?<=\C)a/u',
            'valid' => static fn (int $php, int $pcre): bool => false,
            'errorCode' => 'regex.escape.single_byte_in_utf',
        ];
        yield '{,3} with nothing to repeat, from PCRE2 10.43' => [
            'regex' => '/{,3}/',
            'valid' => static fn (int $php, int $pcre): bool => $pcre < 43,
            'errorCode' => 'regex.quantifier.nothing_to_repeat',
        ];
        // PCRE2 looks "R2" up as a name first: a group named R2 makes it a
        // test of that group, before or after it (preg_match() on 8.4.26
        // matches "ab" with /(?<R2>a)(?(R2)b|c)/); with no such name it asks
        // about a recursion into group 2, which must exist.
        yield 'condition R2 on a group named R2' => [
            'regex' => '/(?<R2>a)(?(R2)b|c)/',
            'valid' => static fn (int $php, int $pcre): bool => true,
            'errorCode' => null,
        ];
        yield 'condition R2 on a group named R2 after it' => [
            'regex' => '/(?(R2)b|c)(?<R2>a)/',
            'valid' => static fn (int $php, int $pcre): bool => true,
            'errorCode' => null,
        ];
        yield 'condition R2 with no group named R2 nor numbered 2' => [
            'regex' => '/(?<R02>a)(?(R2)b|c)/',
            'valid' => static fn (int $php, int $pcre): bool => false,
            'errorCode' => 'regex.subroutine.recursion',
        ];
        yield 'no closing delimiter, never thrown' => [
            'regex' => '/abc',
            'valid' => static fn (int $php, int $pcre): bool => false,
            'errorCode' => 'regex.delimiter.unclosed',
        ];
        yield 'alphanumeric delimiter, never thrown' => [
            'regex' => 'abc',
            'valid' => static fn (int $php, int $pcre): bool => false,
            'errorCode' => 'regex.delimiter.invalid',
        ];
    }

    #[Test]
    public function test_backslash_c_under_u_is_reported_where_each_php_reports_it(): void
    {
        // PHP 8.4.25 and 8.5.10 refuse \C under "u" as they read it, at its
        // end (preg_match() on 8.4.26: offset 6). Earlier ones compile it but
        // refuse it in a lookbehind, at the lookbehind (pcre2test 10.49 under
        // utf: error 136 at offset 0).
        $offsets = [];
        foreach ((new CompatibilityChecker())->check('/(?<=\C)a/u')->verdicts() as $verdict) {
            $offsets[$verdict->target->phpVersionId][] = $verdict->validation->offset;
        }

        $count = \count(self::releases());
        $this->assertSame([
            80200 => array_fill(0, $count, 0),
            80300 => array_fill(0, $count, 0),
            80400 => array_fill(0, $count, 0),
            80425 => array_fill(0, $count, 6),
            80500 => array_fill(0, $count, 0),
            80510 => array_fill(0, $count, 6),
        ], $offsets);
    }

    /**
     * @param \Closure(int, int): bool $valid
     */
    #[Test]
    #[DataProvider('provideRows')]
    public function test_running_engine_verdict_agrees_with_preg_match(string $regex, \Closure $valid, ?string $errorCode): void
    {
        $verdict = self::runningEngineVerdict((new CompatibilityChecker())->check($regex));

        $compiles = false !== @preg_match($regex, '');
        $this->assertSame($compiles, $verdict->validation->isValid, \sprintf('PHP %s with PCRE2 %s', \PHP_VERSION, \PCRE_VERSION));

        if (!$verdict->validation->isValid) {
            $this->assertSame($errorCode, $verdict->validation->errorCode?->value);
        }

        $offset = PhpErrorOffset::of($regex);
        if (null !== $offset) {
            $this->assertSame($offset, $verdict->validation->offset);
        }
    }

    #[Test]
    public function test_check_reads_no_target_from_the_running_engine(): void
    {
        // The same matrix whatever runs the check: two checkers agree.
        $this->assertEquals((new CompatibilityChecker())->check('/a/r'), (new CompatibilityChecker())->check('/a/r'));
        $this->assertInstanceOf(PatternCompatibility::class, (new CompatibilityChecker())->check('/a/r'));
    }

    /**
     * The verdict whose target judges the running engine: the highest PHP
     * point at or below the running PHP, with the running PCRE2 release,
     * clamped to the releases the library knows (an older one is judged
     * with the 10.40 rules, a newer one with the newest).
     */
    private static function runningEngineVerdict(PatternCompatibility $compatibility): TargetVerdict
    {
        $php = max(array_filter(self::PHP_POINTS, static fn (int $point): bool => $point <= \PHP_VERSION_ID));
        $releases = self::releases();
        $running = self::minor(PcreTarget::runtime()->pcreVersion);
        $release = '10.'.max(self::minor($releases[0]), min($running, self::minor($releases[\count($releases) - 1])));

        foreach ($compatibility->verdicts() as $verdict) {
            if ($php === $verdict->target->phpVersionId && $release === $verdict->target->pcreVersion) {
                return $verdict;
            }
        }

        self::fail(\sprintf('No verdict for PHP %d with PCRE2 %s.', $php, $release));
    }

    /**
     * 10.40 up to the newest release a PcreFeature names.
     *
     * @return list<string>
     */
    private static function releases(): array
    {
        $newest = 40;
        foreach (PcreFeature::cases() as $feature) {
            $newest = max($newest, self::minor($feature->release()));
        }

        return array_map(static fn (int $minor): string => '10.'.$minor, range(40, $newest));
    }

    private static function minor(string $release): int
    {
        return (int) explode('.', $release)[1];
    }

    private static function validate(string $regex, PcreTarget $target): ValidationResult
    {
        return RegexParser::create(['php_version' => $target->phpVersionId, 'pcre_version' => $target->pcreVersion])->validate($regex);
    }
}
