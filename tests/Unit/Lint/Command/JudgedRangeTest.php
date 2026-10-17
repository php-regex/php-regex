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

namespace PHPRegex\Tests\Unit\Lint\Command;

use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Config\TargetConstraint;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The versions judged under a constraint: the lowest version the project
 * range and the constraint both admit, with the PCRE2 it bundles or the one
 * named for every version, then each later version of the range the
 * constraint admits. A constraint that keeps none judges nothing.
 */
final class JudgedRangeTest extends TestCase
{
    use TemporaryProject;

    protected function tearDown(): void
    {
        $this->removeTemporaryProjects();
    }

    /**
     * @return iterable<string, array{require: string, constraint: string, expected: list<string>}>
     */
    public static function provideConstraints(): iterable
    {
        $range = ['8.2/10.40', '8.3/10.42', '8.4/10.44', '8.4.25/10.44', '8.5/10.44', '8.5.10/10.44'];
        yield 'the whole range' => ['require' => '^8.2', 'constraint' => 'php>=8.0', 'expected' => $range];
        yield 'a floor' => ['require' => '^8.2', 'constraint' => 'php>=8.4', 'expected' => ['8.4/10.44', '8.4.25/10.44', '8.5/10.44', '8.5.10/10.44']];
        yield 'a ceiling' => ['require' => '^8.2', 'constraint' => 'php<8.4', 'expected' => ['8.2/10.40', '8.3/10.42']];
        yield 'a patch floor' => ['require' => '^8.2', 'constraint' => 'php>=8.4.3', 'expected' => ['8.4.3/10.44', '8.4.25/10.44', '8.5/10.44', '8.5.10/10.44']];
        yield 'both bounds' => ['require' => '^8.2', 'constraint' => 'php>=8.3 <8.5', 'expected' => ['8.3/10.42', '8.4/10.44', '8.4.25/10.44']];
        yield 'an or' => ['require' => '^8.2', 'constraint' => 'php<8.3 || >=8.5', 'expected' => ['8.2/10.40', '8.5/10.44', '8.5.10/10.44']];
        yield 'a release' => ['require' => '^8.2', 'constraint' => 'pcre>=10.43', 'expected' => ['8.4/10.44', '8.4.25/10.44', '8.5/10.44', '8.5.10/10.44']];
        yield 'a release and a version' => ['require' => '^8.2', 'constraint' => 'php<8.5 pcre>=10.42', 'expected' => ['8.3/10.42', '8.4/10.44', '8.4.25/10.44']];
        yield 'above the range' => ['require' => '^8.2', 'constraint' => 'php>=9.0', 'expected' => []];
        yield 'below the range' => ['require' => '>=8.2', 'constraint' => 'php<8.2', 'expected' => []];
        yield 'an open range' => ['require' => '>=8.4', 'constraint' => 'php>=9.0', 'expected' => ['9.0/10.44']];
        // The start of a stretch comes in order among the points.
        yield 'an or with a gap inside a minor' => ['require' => '^8.2', 'constraint' => 'php<8.4 || >=8.4.50', 'expected' => ['8.2/10.40', '8.3/10.42', '8.4.50/10.44', '8.5/10.44', '8.5.10/10.44']];
        // The release a version is judged with is read to the minor.
        yield 'up to a release' => ['require' => '^8.2', 'constraint' => 'pcre<=10.42', 'expected' => ['8.2/10.40', '8.3/10.42']];
    }

    /**
     * @param list<string> $expected "php/pcre" of each version judged, the lowest first
     */
    #[Test]
    #[DataProvider('provideConstraints')]
    public function test_the_versions_judged_under_a_constraint(string $require, string $constraint, array $expected): void
    {
        $target = ProjectTarget::resolve(null, null, [], $this->makeProject(['composer.json' => (string) json_encode(['require' => ['php' => $require]])]), []);

        $this->assertSame($expected, self::labels($target->judgedRange(RegexParser::create($target->regexOptions()))->narrow(self::constraint($constraint))));
    }

    /**
     * A PCRE2 release named for every version is the one the lowest
     * version judged gets too, and a release constraint is read against it.
     */
    #[Test]
    public function test_a_pinned_release_is_judged_at_every_version(): void
    {
        $target = ProjectTarget::resolve(null, '10.42', [], $this->makeProject(['composer.json' => '{"require": {"php": "^8.2"}}']), []);
        $judged = $target->judgedRange(RegexParser::create($target->regexOptions()));

        $this->assertSame(['8.4.3/10.42', '8.4.25/10.42', '8.5/10.42', '8.5.10/10.42'], self::labels($judged->narrow(self::constraint('php>=8.4.3'))));
        $this->assertSame([], $judged->narrow(self::constraint('pcre>=10.43')));
    }

    /**
     * One version named is the series it starts: a constraint inside it
     * is judged from the version it admits, one outside it keeps nothing.
     */
    #[Test]
    public function test_one_version_named_is_its_series(): void
    {
        $target = ProjectTarget::resolve('8.2', null, [], $this->makeProject(), []);
        $judged = $target->judgedRange(RegexParser::create($target->regexOptions()));

        $this->assertSame(['8.2/10.40'], self::labels($judged->narrow(self::constraint('php>=8.0'))));
        $this->assertSame(['8.2.5/10.40'], self::labels($judged->narrow(self::constraint('php>=8.2.5'))));
        $this->assertSame([], $judged->narrow(self::constraint('php>=8.4')));
        $this->assertSame('>=8.2 <8.3', $judged->describe());
    }

    /**
     * The parser of a version judged keeps the floor's cache: the range
     * reads a pattern once for the versions that read it alike.
     */
    #[Test]
    public function test_the_parsers_of_the_versions_judged_share_the_floor_cache(): void
    {
        $target = ProjectTarget::resolve(null, null, [], $this->makeProject(['composer.json' => '{"require": {"php": "^8.2"}}']), []);
        $floor = RegexParser::create($target->regexOptions());
        $judged = $target->judgedRange($floor);

        $this->assertSame($floor, $judged->parserFor($target->target()));
        $parser = $judged->parserFor(new PcreTarget(80400, '10.44'));
        $this->assertSame(80400, $parser->target()->phpVersionId);
        $this->assertSame($floor->getCache(), $parser->getCache());
        $this->assertSame($parser, $judged->parserFor(new PcreTarget(80400, '10.44')));

        // A version is the PHP and its PCRE2: another release, another parser.
        $other = $judged->parserFor(new PcreTarget(80400, '10.42'));
        $this->assertNotSame($parser, $other);
        $this->assertSame('10.42', $other->target()->pcreVersion);
        $this->assertNotSame($floor, $judged->parserFor(new PcreTarget($target->target()->phpVersionId, '10.42')));
    }

    /**
     * The running PHP is judged with the PCRE2 it links, as the floor is,
     * whatever release its version bundles.
     */
    #[Test]
    public function test_the_running_php_keeps_the_pcre2_it_links(): void
    {
        $target = ProjectTarget::resolve(null, null, [], $this->makeProject(), []);
        $judged = $target->judgedRange(RegexParser::create($target->regexOptions()));

        $this->assertEquals([$target->target()], $judged->narrow(self::constraint('php>=7.3')));
        $this->assertSame(PcreTarget::runtime()->pcreVersion, $judged->narrow(self::constraint('php>=7.3'))[0]->pcreVersion);
    }

    /**
     * The constraint as the JSON report writes it, in Composer syntax.
     */
    #[Test]
    public function test_a_constraint_is_written_in_composer_syntax(): void
    {
        $this->assertSame('>=8.4', self::constraint('php>=8.4')->phpConstraint());
        $this->assertSame('<8.4', self::constraint('php<8.4')->phpConstraint());
        $this->assertSame('>=8.2 <8.4', self::constraint('php>=8.2 <8.4')->phpConstraint());
        $this->assertSame('>=8.2 <8.3 || >=8.4.3', self::constraint('php~8.2.0 || >=8.4.3')->phpConstraint());
        $this->assertNull(self::constraint('pcre>=10.43')->phpConstraint());
        $this->assertSame('>=10.43', self::constraint('pcre>=10.43')->pcreConstraint());
        $this->assertSame('>=8.4 <8.5', self::constraint('php>=8.4')->intersect(self::constraint('php<8.5'))->phpConstraint());
        $this->assertSame('>=8.4', TargetConstraint::phpFrom(80400, null)->phpConstraint());
    }

    /**
     * Two constraints meet where both admit: the lower ceiling of two, the
     * stretches sorted whatever order they were written in.
     */
    #[Test]
    public function test_an_intersection_keeps_the_versions_both_admit(): void
    {
        $this->assertSame('>=8.2 <8.4', self::constraint('php<8.5')->intersect(self::constraint('php>=8.2 <8.4'))->phpConstraint());
        $this->assertSame('>=8.2 <8.4', self::constraint('php>=8.2 <8.4')->intersect(self::constraint('php<8.5'))->phpConstraint());
        $this->assertSame('>=8.0 <8.3 || >=8.5', self::constraint('php>=8.0')->intersect(self::constraint('php>=8.5 || <8.3'))->phpConstraint());
        $this->assertSame('>=10.43', self::constraint('php>=8.4')->intersect(self::constraint('pcre>=10.43'))->pcreConstraint());
    }

    /**
     * Two constraints joined admit what either does: stretches that
     * overlap or touch merge, a side that admits every version admits every
     * one.
     */
    #[Test]
    public function test_a_union_keeps_the_versions_either_admits(): void
    {
        $this->assertSame('<8.4', self::constraint('php<8.3')->union(self::constraint('php<8.4'))->phpConstraint());
        $this->assertSame('>=8.2 <8.4', self::constraint('php>=8.2 <8.3')->union(self::constraint('php>=8.3 <8.4'))->phpConstraint());
        $this->assertSame('>=8.2 <8.3 || >=8.4', self::constraint('php>=8.4')->union(self::constraint('php>=8.2 <8.3'))->phpConstraint());
        $this->assertSame('>=8.2', self::constraint('php>=8.2 <8.4')->union(self::constraint('php>=8.3'))->phpConstraint());
        $this->assertSame('>=8.2', self::constraint('php>=8.3')->union(self::constraint('php>=8.2 <8.4'))->phpConstraint());
        $this->assertSame('<10.40 || >=10.43', self::constraint('pcre>=10.43')->union(self::constraint('pcre<10.40'))->pcreConstraint());
        $both = self::constraint('php>=8.4')->union(self::constraint('pcre>=10.43'));
        $this->assertNull($both->phpConstraint());
        $this->assertNull($both->pcreConstraint());
    }

    /**
     * A constraint whose either part cannot be read is none.
     */
    #[Test]
    public function test_a_constraint_with_a_part_that_cannot_be_read_is_none(): void
    {
        $this->assertNull(TargetConstraint::fromMarker('php>=eight pcre>=10.43'));
        $this->assertNull(TargetConstraint::fromMarker('php>=8.4 pcre>=ten'));
    }

    private static function constraint(string $text): TargetConstraint
    {
        $constraint = TargetConstraint::fromMarker($text);
        self::assertNotNull($constraint, $text);

        return $constraint;
    }

    /**
     * @param list<PcreTarget> $targets
     *
     * @return list<string>
     */
    private static function labels(array $targets): array
    {
        return array_map(static fn (PcreTarget $target): string => ProjectTarget::phpLabel($target->phpVersionId).'/'.$target->pcreVersion, $targets);
    }
}
