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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\RangeFindings;
use PHPRegex\Linter\SilentMeaningChange;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\TargetVerdict;
use PHPRegex\Tests\Support\PcreRule;
use PHPRegex\Tests\Support\RangeFixtureReadings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the later versions of a range find in a pattern the floor accepts,
 * decided once for the lint and the language server, in one order: the
 * lowest version that refuses it; else the lowest that reads it into
 * another tree; else, only when nothing else is found and the rule is on,
 * a change of meaning that keeps the tree.
 *
 * The ranges are those a --php-version constraint names, each point with
 * the PCRE2 it is judged with (PcreRule).
 */
final class RangeFindingsTest extends TestCase
{
    use PcreRule;
    use RangeFixtureReadings;

    /**
     * Compiles on PHP 8.4 (preg_match() returns 1 on 8.4.27, PCRE2 10.49);
     * PHP 8.5 compiles without PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK, and PCRE2
     * refuses "\K" in a lookbehind there.
     */
    private const KEEP_IN_LOOKBEHIND = '/(?<=a\Kb)c/';

    /**
     * Text before PCRE2 10.43, a quantifier from it: preg_match() of
     * "/^a{,3}$/" matches "aa" on 10.49.
     */
    private const QUANTIFIER_WITHOUT_MIN = '/a{,3}/';

    /**
     * pcre2test 10.34: no match in "abb"; 10.35: "abb" (preg_match() on
     * 10.49 finds "abb" too). PHP 7.4 bundles 10.33, PHP 8.0 10.35.
     */
    private const SELF_REFERENCE = '/^(a|ab|\1x)+b$/';

    private const SELF_REFERENCE_CHANGE = 'a group that holds a reference to itself may be backtracked into; before, PCRE2 made such a group atomic: /^(a|ab|\1x)+b$/ finds "abb" in "abb" from 10.35 and nothing before.';

    #[Test]
    public function test_range_findings_name_the_lowest_version_that_refuses(): void
    {
        self::assertEngineKeepInLookbehind();

        [$floor, $range] = self::range('>=8.1');

        $findings = self::findings($floor, $range, self::KEEP_IN_LOOKBEHIND);

        $this->assertInstanceOf(TargetVerdict::class, $findings->refusedBy);
        $this->assertSame(80500, $findings->refusedBy->target->phpVersionId);
        $this->assertFalse($findings->refusedBy->validation->isValid);
        $this->assertSame(self::parserAt($range, 80500)->validate(self::KEEP_IN_LOOKBEHIND)->error, $findings->refusedBy->validation->error);
        $this->assertSame('PHP 8.5 and later', $findings->refusedOn);
        $this->assertNull($findings->readsOtherwise);
        $this->assertNull($findings->silentChange);
    }

    #[Test]
    public function test_range_findings_name_the_lowest_version_that_reads_another_tree(): void
    {
        self::assertEngineReadsQuantifierWithoutMin();

        [$floor, $range] = self::range('>=8.1');
        $expected = self::readsQuantifierOtherwiseFrom([80100, 80200, 80300, 80400, 80425, 80500, 80510]);
        $this->assertNotNull($expected, 'No point of the range reads "a{,3}" otherwise than the floor here.');

        $findings = self::findings($floor, $range, self::QUANTIFIER_WITHOUT_MIN);

        $this->assertNull($findings->refusedBy);
        $this->assertSame('', $findings->refusedOn);
        $this->assertInstanceOf(PcreTarget::class, $findings->readsOtherwise);
        $this->assertSame($expected, $findings->readsOtherwise->phpVersionId);
        $this->assertSame(self::judgedPcre($expected), $findings->readsOtherwise->pcreVersion);
        $this->assertNull($findings->silentChange);
    }

    #[Test]
    public function test_range_findings_name_a_silent_change_of_meaning_when_nothing_else(): void
    {
        self::assertEngineBacktracksIntoSelfReference();

        [$floor, $range] = self::range('>=7.4 <8.1');

        $findings = self::findings($floor, $range, self::SELF_REFERENCE);

        $this->assertNull($findings->refusedBy);
        $this->assertSame('', $findings->refusedOn);
        $this->assertNull($findings->readsOtherwise);
        $this->assertInstanceOf(SilentMeaningChange::class, $findings->silentChange);
        $this->assertSame(80000, $findings->silentChange->target->phpVersionId);
        $this->assertSame(self::judgedPcre(80000), $findings->silentChange->target->pcreVersion);
        $this->assertSame(self::SELF_REFERENCE_CHANGE, $findings->silentChange->message);
        $this->assertSame('Wrap the group in an atomic group, "(?>(...))", to keep the older reading on every PHP.', $findings->silentChange->hint);
    }

    /**
     * A later version that refuses the pattern hides what another reads
     * otherwise: the same quantifier behind no "\K" is read otherwise.
     */
    #[Test]
    public function test_range_findings_give_a_refusal_alone_over_another_tree(): void
    {
        self::assertEngineKeepInLookbehind();
        self::assertEngineReadsQuantifierWithoutMin();

        [$floor, $range] = self::range('>=8.1');
        $this->assertInstanceOf(PcreTarget::class, self::findings($floor, $range, '/(?<=ab)c{,3}/')->readsOtherwise, 'The quantifier is read otherwise without the refusal.');

        $findings = self::findings($floor, $range, '/(?<=a\Kb)c{,3}/');

        $this->assertInstanceOf(TargetVerdict::class, $findings->refusedBy);
        $this->assertSame(80500, $findings->refusedBy->target->phpVersionId);
        $this->assertSame('PHP 8.5 and later', $findings->refusedOn);
        $this->assertNull($findings->readsOtherwise);
        $this->assertNull($findings->silentChange);
    }

    #[Test]
    public function test_range_findings_give_a_refusal_alone_over_a_silent_change(): void
    {
        self::assertEngineKeepInLookbehind();
        self::assertEngineBacktracksIntoSelfReference();

        [$floor, $range] = self::range('>=7.4');

        $findings = self::findings($floor, $range, '/(?<=a\Kb)^(a|ab|\1x)+b$/');

        $this->assertInstanceOf(TargetVerdict::class, $findings->refusedBy);
        $this->assertSame(80500, $findings->refusedBy->target->phpVersionId);
        $this->assertNull($findings->readsOtherwise);
        $this->assertNull($findings->silentChange);
    }

    #[Test]
    public function test_range_findings_give_another_tree_alone_over_a_silent_change(): void
    {
        self::assertEngineReadsQuantifierWithoutMin();
        self::assertEngineBacktracksIntoSelfReference();

        [$floor, $range] = self::range('>=7.4');
        $expected = self::readsQuantifierOtherwiseFrom([70400, 80000, 80100, 80200, 80300, 80400, 80425, 80500, 80510]);
        $this->assertNotNull($expected);

        $findings = self::findings($floor, $range, '/^(a|ab|\1x)+b{,3}$/');

        $this->assertNull($findings->refusedBy);
        $this->assertInstanceOf(PcreTarget::class, $findings->readsOtherwise);
        $this->assertSame($expected, $findings->readsOtherwise->phpVersionId);
        $this->assertNull($findings->silentChange);
    }

    /**
     * The rule that reports a change of meaning off: the trees are not
     * compared and no silent change is looked for; a refusal still is.
     */
    #[Test]
    public function test_range_findings_without_the_meaning_rule_give_refusals_only(): void
    {
        [$floor, $range] = self::range('>=8.1');
        $quantifier = self::findings($floor, $range, self::QUANTIFIER_WITHOUT_MIN, comparesTrees: false);
        $refused = self::findings($floor, $range, self::KEEP_IN_LOOKBEHIND, comparesTrees: false);
        [$oldFloor, $oldRange] = self::range('>=7.4 <8.1');
        $selfReference = self::findings($oldFloor, $oldRange, self::SELF_REFERENCE, comparesTrees: false);

        $this->assertNull($quantifier->refusedBy);
        $this->assertNull($quantifier->readsOtherwise);
        $this->assertNull($quantifier->silentChange);
        $this->assertNull($selfReference->readsOtherwise);
        $this->assertNull($selfReference->silentChange);
        $this->assertInstanceOf(TargetVerdict::class, $refused->refusedBy);
        $this->assertSame(80500, $refused->refusedBy->target->phpVersionId);
        $this->assertSame('PHP 8.5 and later', $refused->refusedOn);
    }

    /**
     * A range of one version, the floor alone, finds nothing.
     */
    #[Test]
    public function test_range_findings_without_a_later_version_find_nothing(): void
    {
        foreach (['8.1' => self::KEEP_IN_LOOKBEHIND, '8.2' => self::QUANTIFIER_WITHOUT_MIN, '7.4' => self::SELF_REFERENCE] as $php => $pattern) {
            $floor = RegexParser::create(['php_version' => (string) $php]);

            $findings = self::findings($floor, [], $pattern);

            $this->assertNull($findings->refusedBy, $pattern);
            $this->assertSame('', $findings->refusedOn, $pattern);
            $this->assertNull($findings->readsOtherwise, $pattern);
            $this->assertNull($findings->silentChange, $pattern);
        }
    }

    /**
     * @param list<RegexParser> $range
     */
    private static function findings(RegexParser $floor, array $range, string $pattern, bool $comparesTrees = true): RangeFindings
    {
        return RangeFindings::of($floor, $range, $pattern, $floor->parse($pattern), $comparesTrees);
    }

    /**
     * The parser of the floor a --php-version constraint names, and those
     * of the later points, sharing its cache as lint's do.
     *
     * @return array{RegexParser, list<RegexParser>}
     */
    private static function range(string $constraint): array
    {
        $target = ProjectTarget::fromSources(['--php-version' => $constraint], [], null, []);
        $floor = RegexParser::create($target->regexOptions());

        return [$floor, $target->rangeParsers($floor)];
    }

    /**
     * @param list<RegexParser> $range
     */
    private static function parserAt(array $range, int $phpVersionId): RegexParser
    {
        foreach ($range as $parser) {
            if ($phpVersionId === $parser->target()->phpVersionId) {
                return $parser;
            }
        }

        self::fail(\sprintf('The range holds no point at %d.', $phpVersionId));
    }
}
