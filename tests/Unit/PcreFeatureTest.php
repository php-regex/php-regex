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
        yield 'scan substring' => [PcreFeature::ScanSubstring, '10.44', '10.45'];
        yield 'offsets past the fault' => [PcreFeature::ErrorOffsetPastTheFault, '10.46', '10.47'];
        yield 'branch count off the condition name' => [PcreFeature::BranchCountErrorOffTheConditionName, '10.46', '10.47'];
        yield 'variable-length lookbehind' => [PcreFeature::VariableLengthLookbehind, '10.42', '10.43'];
        yield 'long group names' => [PcreFeature::LongGroupNames, '10.43', '10.44'];
        yield 'unclosed brace at the end' => [PcreFeature::UnclosedBraceAtPatternEnd, '10.47', '10.48'];
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
