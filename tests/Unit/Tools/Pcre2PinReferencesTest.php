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

use PHPRegex\Tests\TestUtils\Pcre2TestdataExtractor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The release the vendored suite comes from is named in one constant; the
 * job that re-observes the fixture, the fixture's README and the
 * conformance page name the same one, so a re-pin leaves none of them on
 * the old release.
 */
#[Group('documentation')]
final class Pcre2PinReferencesTest extends TestCase
{
    #[Test]
    public function test_the_fixture_job_builds_the_floor_and_the_pin(): void
    {
        $floor = Pcre2TestdataExtractor::PCRE2_FLOOR;
        $pin = Pcre2TestdataExtractor::PCRE2_PIN;
        $workflow = (string) file_get_contents(self::root().'/.github/workflows/engines.yml');
        $this->assertSame(1, preg_match('~\n  pcre2-fixture:\n(.*?)(?=\n  [\w-]+:\n|\z)~s', $workflow, $job), 'engines.yml has a pcre2-fixture job.');

        $this->assertStringContainsString('key: pcre2test-'.$floor.'-'.$pin.'-v', $job[1]);
        $this->assertStringContainsString('for version in '.$floor.' '.$pin.'; do', $job[1]);
        $this->assertStringContainsString('--floor-pcre2test="$RUNNER_TEMP/pcre2/pcre2-'.$floor.'/pcre2test"', $job[1]);
        $this->assertStringContainsString('--pin-pcre2test="$RUNNER_TEMP/pcre2/pcre2-'.$pin.'/pcre2test"', $job[1]);
        $this->assertSame([], self::releasesOtherThan($job[1], [$floor, $pin]));
    }

    #[Test]
    public function test_the_fixture_readme_names_the_pinned_tag(): void
    {
        $readme = (string) file_get_contents(self::root().'/tests/Fixtures/Pcre2/README.md');
        $this->assertSame(1, preg_match('~^## Source\n(.*?)(?=^## )~ms', $readme, $source), 'The README has a Source section.');

        $this->assertStringContainsString('Pinned tag: [`pcre2-'.Pcre2TestdataExtractor::PCRE2_PIN.'`]', $source[1]);
        $this->assertSame(9, substr_count($source[1], '/blob/pcre2-'.Pcre2TestdataExtractor::PCRE2_PIN.'/'));
        $this->assertSame([], self::releasesOtherThan($source[1], [Pcre2TestdataExtractor::PCRE2_PIN]));
    }

    #[Test]
    public function test_the_conformance_page_names_the_pinned_release(): void
    {
        $page = (string) file_get_contents(self::root().'/docs/reference/pcre2-conformance.md');
        [$prose] = explode('<!-- pcre2-conformance: generated below - do not edit -->', $page, 2);

        $this->assertStringContainsString('pinned to '.Pcre2TestdataExtractor::PCRE2_PIN.'."', $prose);
        $this->assertStringContainsString('this page describes PCRE2 '.Pcre2TestdataExtractor::PCRE2_PIN.', pinned', $prose);
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    private static function releasesOtherThan(string $text, array $allowed): array
    {
        preg_match_all('~(?<![\d.])10\.\d{2}(?![\d])~', $text, $releases);

        return array_values(array_unique(array_diff($releases[0], $allowed)));
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
