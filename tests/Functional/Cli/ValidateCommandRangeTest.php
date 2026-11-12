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

namespace PHPRegex\Tests\Functional\Cli;

use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RangePatterns;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Tests\Support\SinglePatternRange;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * regex validate judges a --php-version range as lint does: the pattern is
 * validated at the floor and at every later version of the range. The
 * "validation" object keeps the floor's verdict; the "range" list gives
 * each version's; a later version that refuses the pattern makes the run
 * exit 1, and one that reads it into another tree is a warning.
 */
final class ValidateCommandRangeTest extends TestCase
{
    use RunsRegexCli;
    use SinglePatternRange;
    use TemporaryProject;

    #[Test]
    public function test_validate_exits_1_when_a_later_php_of_the_range_refuses_the_pattern(): void
    {
        self::assertEngineKeepInLookbehind();
        $this->enterProject();

        [$exitCode, $stdout] = $this->runRegex(['--php-version=>=8.1', 'validate', RangePatterns::KEEP_IN_LOOKBEHIND, '--format=json']);

        $this->assertSame(1, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('validate', $document);
        // The floor, PHP 8.1, compiles it: the verdict of "validation".
        $this->assertTrue(JsonContract::asArray($document['validation'] ?? null)['is_valid'] ?? null, $stdout);
        self::assertRange(
            $document['range'] ?? null,
            RangePatterns::FROM_8_1,
            RangePatterns::KEEP_IN_LOOKBEHIND,
            static fn (int $point): bool => $point < 80500,
            static fn (int $point): bool => false,
        );
        $refused = JsonContract::asArray(JsonContract::asArray($document['range'])[5]);
        $this->assertSame(['8.5', 'regex.keep.in_lookaround'], [$refused['php'], $refused['error_code']]);
    }

    #[Test]
    public function test_validate_names_a_later_refusal_in_the_words_of_lint(): void
    {
        self::assertEngineKeepInLookbehind();
        $this->enterProject();

        [$exitCode, $stdout, $stderr] = $this->runRegex(['--php-version=>=8.1', 'validate', RangePatterns::KEEP_IN_LOOKBEHIND]);

        $this->assertSame(1, $exitCode, $stdout.$stderr);
        $this->assertStringContainsString(self::refusedOnMessage('PHP 8.5 and later', 80500, RangePatterns::KEEP_IN_LOOKBEHIND), $stdout.$stderr);
    }

    #[Test]
    public function test_validate_warns_when_a_later_php_parses_the_pattern_differently(): void
    {
        self::assertEngineReadsQuantifierWithoutMin();
        $this->enterProject();

        [$exitCode, $stdout] = $this->runRegex(['--php-version=>=8.1', 'validate', RangePatterns::QUANTIFIER_WITHOUT_MIN, '--format=json']);

        $this->assertSame(0, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('validate', $document);
        $this->assertTrue(JsonContract::asArray($document['validation'] ?? null)['is_valid'] ?? null, $stdout);
        // Every version whose PCRE2 reads "{,3}" otherwise than the floor's
        // (a quantifier from 10.43, text before) parses it into another
        // tree: 8.4 on with the bundled releases, 8.2 and 8.3 where PHP 8.1
        // links 10.43 or later.
        self::assertRange(
            $document['range'] ?? null,
            RangePatterns::FROM_8_1,
            RangePatterns::QUANTIFIER_WITHOUT_MIN,
            static fn (int $point): bool => true,
            self::readsQuantifierOtherwiseThanTheFloor(RangePatterns::FROM_8_1),
        );

        [$exitCode, $stdout, $stderr] = $this->runRegex(['--php-version=>=8.1', 'validate', RangePatterns::QUANTIFIER_WITHOUT_MIN]);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertStringContainsString(self::parsesDifferentlyMessage(self::firstPointReadingQuantifierOtherwise(RangePatterns::FROM_8_1)), $stdout.$stderr);
    }

    /**
     * The "target" object is lint's, from the one producer: the same
     * --php-version gives the same object to both commands.
     */
    #[Test]
    public function test_validate_names_the_target_lint_names_for_the_same_flag(): void
    {
        $directory = $this->enterProject(['src/a.php' => "<?php\n\npreg_match('/a/', \$s);\n"]);

        [$exitCode, $stdout] = $this->runRegex(['--php-version=>=8.1', 'validate', '/a/', '--format=json']);
        [, $lint] = $this->runRegex(['--php-version=>=8.1', 'lint', 'src', '--jobs=1', '--no-redos', '--format=json']);

        $this->assertSame(0, $exitCode, $stdout);
        $target = JsonContract::decodeDocument($stdout)['target'] ?? null;
        $this->assertSame(JsonContract::decodeDocument($lint)['target'] ?? null, $target);
        $this->assertSame(ProjectTarget::resolve('>=8.1', null, [], $directory, [])->toArray(), $target);
        $this->assertSame('--php-version', JsonContract::asArray($target)['source'] ?? null);
    }

    /**
     * The boundaries of a range: one that starts at the last version the
     * library knows, one above every version it knows, a single version,
     * and a range that ends before the refusing version.
     *
     * @param list<int> $points
     */
    #[Test]
    #[DataProvider('provideRangeBoundaries')]
    public function test_validate_judges_exactly_the_versions_the_range_allows(string $phpVersion, string $pattern, array $points, bool $floorValid, int $exitCode): void
    {
        self::assertEngineKeepInLookbehind();
        self::assertEngineReadsQuantifierWithoutMin();
        $this->enterProject();

        [$actualExitCode, $stdout] = $this->runRegex(['--php-version='.$phpVersion, 'validate', $pattern, '--format=json']);

        $this->assertSame($exitCode, $actualExitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('validate', $document);
        $this->assertSame($floorValid, JsonContract::asArray($document['validation'] ?? null)['is_valid'] ?? null, $stdout);
        $readsOtherwise = self::readsQuantifierOtherwiseThanTheFloor($points);
        self::assertRange(
            $document['range'] ?? null,
            $points,
            $pattern,
            static fn (int $point): bool => RangePatterns::KEEP_IN_LOOKBEHIND !== $pattern || $point < 80500,
            static fn (int $point): bool => RangePatterns::QUANTIFIER_WITHOUT_MIN === $pattern && $readsOtherwise($point),
        );
    }

    /**
     * @return iterable<string, array{phpVersion: string, pattern: string, points: list<int>, floorValid: bool, exitCode: int}>
     */
    public static function provideRangeBoundaries(): iterable
    {
        // The last version the library knows: one version, which refuses it.
        yield 'from the last point, refused at its floor' => ['phpVersion' => '>=8.5.10', 'pattern' => RangePatterns::KEEP_IN_LOOKBEHIND, 'points' => [80510], 'floorValid' => false, 'exitCode' => 1];
        // Above every version the library knows: the floor alone.
        yield 'above every point' => ['phpVersion' => '>8.6', 'pattern' => RangePatterns::QUANTIFIER_WITHOUT_MIN, 'points' => [80601], 'floorValid' => true, 'exitCode' => 0];
        // A single version stays exact: 8.5 is not judged.
        yield 'a single version' => ['phpVersion' => '8.1', 'pattern' => RangePatterns::KEEP_IN_LOOKBEHIND, 'points' => [80100], 'floorValid' => true, 'exitCode' => 0];
        // The range ends before the version that refuses the pattern.
        yield 'a hyphen range below the refusal' => ['phpVersion' => '8.1 - 8.4', 'pattern' => RangePatterns::KEEP_IN_LOOKBEHIND, 'points' => [80100, 80200, 80300, 80400, 80425], 'floorValid' => true, 'exitCode' => 0];
        yield 'a caret range with an upper bound' => ['phpVersion' => '^8.1 <8.6', 'pattern' => RangePatterns::KEEP_IN_LOOKBEHIND, 'points' => RangePatterns::FROM_8_1, 'floorValid' => true, 'exitCode' => 1];
    }
}
