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

namespace PHPRegex\Tests\Integration\Bridge\Psalm;

use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Psalm\MatchesType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under PREG_OFFSET_CAPTURE, PHP writes the name of a mark verb as a plain
 * string over the offset pair of a group named MARK. The plugin types the
 * key as both. Psalm's own type of the matches under that flag holds an
 * offset pair under every key, and from Psalm 7.21 it narrows what the
 * call asserts by it, which leaves the mark out: what Psalm traces there
 * is Psalm's reading, not the plugin's.
 */
final class PsalmPluginMarkWithOffsetsTest extends TestCase
{
    private const FILE = 'Marks/group_named_mark_with_offsets.php';

    private const PATTERN = '/(?<MARK>a)(*MARK:m)/';

    #[Test]
    public function test_a_group_named_mark_beside_a_verb_holds_the_mark_under_offset_capture(): void
    {
        $this->assertSame(1, preg_match(self::PATTERN, 'a', $matches, \PREG_OFFSET_CAPTURE));
        // PHP 8.5 writes the key MARK twice, the pair then the mark; a read
        // of the key gives the mark on every PHP.
        $this->assertSame(['a', 0], $matches[0]);
        $this->assertSame(['a', 0], $matches[1]);
        $this->assertSame('m', $matches['MARK']);

        PsalmTypes::codebase();
        $shape = (new CaptureShapeAnalyzer())->analyze(RegexParser::create(['php_version' => '8.4', 'pcre_version' => '10.44'])->parse(self::PATTERN));
        // The plugin's type holds the mark beside the pair, in either order.
        $this->assertMatchesRegularExpression("/MARK: (?:'m'\\|list\\{'a', int<0, max>\\}|list\\{'a', int<0, max>\\}\\|'m')\\}$/", MatchesType::ofMatch($shape, \PREG_OFFSET_CAPTURE)->getId());

        $issues = PsalmRun::inFile(PsalmRun::issues([self::FILE]), self::FILE);
        $mark = InstalledVersions::satisfies(new VersionParser(), 'vimeo/psalm', '>=7.21') ? "list{'a', int<0, max>}" : "'m'|list{'a', int<0, max>}";

        $this->assertSame(
            [\sprintf("Trace 21:0 \$n: array{0: list{'a', int<0, max>}, 1: list{'a', int<0, max>}, MARK: %s}", $mark)],
            array_map(PsalmRun::describe(...), $issues),
        );
    }
}
