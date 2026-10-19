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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Parser\Cache\StaticCaches;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What changed in which PCRE2 release is one table: a rule asks whether its
 * target has a behaviour, never whether it runs a release written by hand.
 */
final class PcreFeatureTest extends TestCase
{
    #[Test]
    public function test_every_feature_names_the_release_it_arrived_in(): void
    {
        foreach (PcreFeature::cases() as $feature) {
            $this->assertMatchesRegularExpression('/^10\.\d{2}$/', $feature->release(), $feature->name);
        }
    }

    #[Test]
    #[DataProvider('provideReleases')]
    public function test_a_target_has_a_feature_from_its_release_on(PcreFeature $feature, string $before, string $from): void
    {
        $this->assertFalse((new PcreTarget(80400, $before))->supports($feature));
        $this->assertTrue((new PcreTarget(80400, $from))->supports($feature));
    }

    /**
     * @return iterable<string, array{PcreFeature, string, string}>
     */
    public static function provideReleases(): iterable
    {
        yield 'alphabetic assertion names' => [PcreFeature::AlphabeticAssertionNames, '10.32', '10.33'];
        yield 'large open count as text' => [PcreFeature::LargeOpenCountIsText, '10.36', '10.37'];
        yield 'script runs' => [PcreFeature::ScriptRuns, '10.32', '10.33'];
        yield 'group names past ASCII' => [PcreFeature::NonAsciiGroupNames, '10.32', '10.33'];
        yield 'bad escape in a class stays refused' => [PcreFeature::BadEscapeInClassStaysRefused, '10.32', '10.33'];
        yield 'names read whole' => [PcreFeature::NamesReadWhole, '10.32', '10.33'];
        yield 'repeated accept' => [PcreFeature::QuantifiedAccept, '10.33', '10.34'];
        yield 'possessive repeat once' => [PcreFeature::PossessiveRepeatOnceIsAtomic, '10.33', '10.34'];
        yield 'non-atomic assertion names' => [PcreFeature::NonAtomicAssertionNames, '10.33', '10.34'];
        yield 'lookbehind in a lookahead measured' => [PcreFeature::LookbehindInLookaheadMeasured, '10.33', '10.34'];
        yield 'DEFINE skipped in a lookbehind' => [PcreFeature::DefineSkippedInLookbehind, '10.34', '10.35'];
        yield 'non-atomic assertion short syntax' => [PcreFeature::NonAtomicAssertionShortSyntax, '10.34', '10.35'];
        yield 'overflowing backslash number as octal' => [PcreFeature::OverflowingBackslashNumberIsOctal, '10.36', '10.37'];
        yield 'scan substring' => [PcreFeature::ScanSubstring, '10.44', '10.45'];
        yield 'offsets past the fault' => [PcreFeature::ErrorOffsetPastTheFault, '10.46', '10.47'];
        yield 'branch count off the condition name' => [PcreFeature::BranchCountErrorOffTheConditionName, '10.46', '10.47'];
        yield 'variable-length lookbehind' => [PcreFeature::VariableLengthLookbehind, '10.42', '10.43'];
        yield 'long group names' => [PcreFeature::LongGroupNames, '10.43', '10.44'];
        yield 'unclosed brace at the end' => [PcreFeature::UnclosedBraceAtPatternEnd, '10.47', '10.48'];
    }

    /**
     * What a target answers depends on its release alone, never on the
     * targets asked before it: releases are walked up, then down, every
     * feature asked of each, and each answer held to the release it names.
     */
    #[Test]
    public function test_a_target_answers_for_its_own_release_whatever_was_asked_before(): void
    {
        $releases = array_map(static fn (int $minor): string => '10.'.$minor, range(30, 50));

        foreach ([$releases, array_reverse($releases)] as $walk) {
            foreach ($walk as $release) {
                $target = new PcreTarget(80400, $release);
                foreach (PcreFeature::cases() as $feature) {
                    $expected = version_compare($release, $feature->release(), '>=');
                    $this->assertSame($expected, $target->supports($feature), $release.' '.$feature->name);
                    $this->assertSame($expected, $target->pcreAtLeast($feature->release()), $release.' '.$feature->name);
                }
            }
        }
    }

    #[Test]
    public function test_a_target_answers_the_same_once_the_process_caches_are_emptied(): void
    {
        $older = new PcreTarget(80400, '10.44');
        $newer = new PcreTarget(80400, '10.45');
        $this->assertFalse($older->supports(PcreFeature::ScanSubstring));
        $this->assertTrue($newer->supports(PcreFeature::ScanSubstring));

        StaticCaches::clear();

        $this->assertTrue($newer->supports(PcreFeature::ScanSubstring));
        $this->assertFalse($older->supports(PcreFeature::ScanSubstring));
    }

    #[Test]
    public function test_a_malformed_release_is_still_refused_after_supports_answered(): void
    {
        $target = new PcreTarget(80400, '10.40');
        $target->supports(PcreFeature::ScanSubstring);

        $this->expectException(InvalidRegexOptionException::class);

        $target->pcreAtLeast('10.4');
    }

    /**
     * "10.4" is no release: read as 10.04, it would hold for every target.
     */
    #[Test]
    public function test_a_release_spelled_short_is_refused(): void
    {
        $this->expectException(InvalidRegexOptionException::class);

        (new PcreTarget(80400, '10.40'))->pcreAtLeast('10.4');
    }

    /**
     * No rule in the code names a release by hand: each asks for a feature.
     */
    #[Test]
    public function test_no_rule_names_a_release_by_hand(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../src', \FilesystemIterator::SKIP_DOTS));
        $found = [];
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()
                && 1 === preg_match_all("/pcreAtLeast\\('10\\./", (string) file_get_contents($file->getPathname()))) {
                $found[] = $file->getFilename();
            }
        }

        $this->assertSame([], $found);
    }
}
