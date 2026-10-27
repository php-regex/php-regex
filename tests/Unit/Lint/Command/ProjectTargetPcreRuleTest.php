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
use PHPRegex\Linter\Exception\UnsupportedTargetException;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The PCRE2 each version the lint judges is judged with, the rule every
 * entry point follows: a release pinned by an option or regex.json, else by
 * composer.json's config.platform.lib-pcre, is used as is, older or newer
 * than the one that PHP bundles; else the running PCRE2 when the version is
 * the running PHP's minor; else the PCRE2 that PHP bundles.
 */
final class ProjectTargetPcreRuleTest extends TestCase
{
    use TemporaryProject;

    protected function tearDown(): void
    {
        $this->removeTemporaryProjects();
    }

    #[Test]
    public function test_the_running_minor_named_gets_the_running_pcre2(): void
    {
        $target = ProjectTarget::resolve(self::runningMinorLabel(), null, [], $this->makeProject(), []);

        $this->assertSame(self::runningMinor(), $target->target()->phpVersionId);
        $this->assertSame(PcreTarget::runtime()->pcreVersion, $target->target()->pcreVersion);
    }

    #[Test]
    public function test_regex_json_naming_the_running_minor_gets_the_running_pcre2(): void
    {
        $target = ProjectTarget::resolve(null, null, ['phpVersion' => self::runningMinorLabel()], $this->makeProject(), []);

        $this->assertSame(PcreTarget::runtime()->pcreVersion, $target->target()->pcreVersion);
    }

    #[Test]
    public function test_a_composer_floor_of_the_running_minor_gets_the_running_pcre2(): void
    {
        $target = ProjectTarget::resolve(null, null, [], $this->makeProject(['composer.json' => \sprintf('{"config": {"platform": {"php": "%s"}}}', self::runningMinorLabel())]), []);

        $this->assertSame(self::runningMinor(), $target->target()->phpVersionId);
        $this->assertSame(PcreTarget::runtime()->pcreVersion, $target->target()->pcreVersion);
    }

    #[Test]
    public function test_another_minor_gets_the_pcre2_it_bundles(): void
    {
        $other = self::otherMinor();
        $target = ProjectTarget::resolve(ProjectTarget::phpLabel($other), null, [], $this->makeProject(), []);

        $this->assertSame(PcreTarget::bundledWith($other)->pcreVersion, $target->target()->pcreVersion);
    }

    #[Test]
    public function test_a_pin_is_used_as_is_in_both_directions(): void
    {
        $project = $this->makeProject();

        $this->assertSame('10.39', ProjectTarget::resolve('8.4', '10.39', [], $project, [])->target()->pcreVersion);
        $this->assertSame('10.49', ProjectTarget::resolve('8.2', '10.49', [], $project, [])->target()->pcreVersion);
        $this->assertSame('10.40', ProjectTarget::resolve(self::runningMinorLabel(), null, ['pcreVersion' => '10.40'], $project, [])->target()->pcreVersion);
    }

    /**
     * composer.json's config.platform.lib-pcre pins the PCRE2 for the floor
     * and every later version of the range; an option or regex.json wins
     * over it.
     */
    #[Test]
    public function test_composer_json_lib_pcre_pins_every_version(): void
    {
        $project = $this->makeProject(['composer.json' => '{"require": {"php": "^8.2"}, "config": {"platform": {"lib-pcre": "10.43"}}}']);

        $target = ProjectTarget::resolve(null, null, [], $project, []);
        $this->assertSame('10.43', $target->target()->pcreVersion);
        $this->assertGreaterThan(1, \count($target->range()));
        foreach ($target->range() as $point) {
            $this->assertSame('10.43', $point->pcreVersion, (string) $point->phpVersionId);
        }
        $this->assertSame('composer.json require.php; composer.json config.platform.lib-pcre', $target->source());

        $judged = $target->judgedRange(RegexParser::create($target->regexOptions()));
        $this->assertSame('10.43', $judged->narrow(TargetConstraint::phpFrom(80405, 80500))[0]->pcreVersion);

        $this->assertSame('10.39', ProjectTarget::resolve(null, '10.39', [], $project, [])->target()->pcreVersion);
        $this->assertSame('10.40', ProjectTarget::resolve(null, null, ['pcreVersion' => '10.40'], $project, [])->target()->pcreVersion);
        $this->assertSame('10.43', ProjectTarget::resolve('8.3', null, [], $project, [])->target()->pcreVersion);
    }

    #[Test]
    public function test_composer_json_lib_pcre_reads_as_composer_spells_it(): void
    {
        $project = $this->makeProject(['composer.json' => '{"config": {"platform": {"php": "8.2", "lib-pcre": "10.42.0"}}}']);

        $this->assertSame('10.42', ProjectTarget::resolve(null, null, [], $project, [])->target()->pcreVersion);
    }

    /**
     * Reading composer.json never fails the lint: a lib-pcre that names no
     * release is passed over with a notice.
     */
    #[Test]
    public function test_composer_json_lib_pcre_that_names_no_release_is_passed_over_with_a_notice(): void
    {
        $project = $this->makeProject(['composer.json' => '{"config": {"platform": {"php": "8.2", "lib-pcre": "ten"}}}']);

        $target = ProjectTarget::resolve(null, null, [], $project, []);

        $this->assertSame(PcreTarget::bundledWith(80200)->pcreVersion, $target->target()->pcreVersion);
        $this->assertContains('composer.json config.platform.lib-pcre "ten" is not a PCRE2 release like "10.44": passed over.', $target->notices());
    }

    /**
     * The COMPOSER variable names the file read, for lib-pcre as for the PHP.
     */
    #[Test]
    public function test_the_composer_variable_names_the_file_lib_pcre_is_read_from(): void
    {
        $project = $this->makeProject(['other.json' => '{"config": {"platform": {"php": "8.2", "lib-pcre": "10.41"}}}']);

        $this->assertSame('10.41', ProjectTarget::resolve(null, null, [], $project, ['COMPOSER' => 'other.json'])->target()->pcreVersion);
    }

    /**
     * A later version of the range of the running PHP's minor is judged
     * with the running PCRE2, as a version a guard or a marker keeps there.
     */
    #[Test]
    public function test_the_range_follows_the_rule(): void
    {
        $other = self::otherMinor();
        $project = $this->makeProject(['composer.json' => \sprintf('{"require": {"php": ">=%s"}}', ProjectTarget::phpLabel(min($other, self::runningMinor())))]);
        $expected = static fn (int $php): string => intdiv($php, 100) === intdiv(\PHP_VERSION_ID, 100) ? PcreTarget::runtime()->pcreVersion : PcreTarget::bundledWith($php)->pcreVersion;

        $target = ProjectTarget::resolve(null, null, [], $project, []);
        foreach ($target->range() as $point) {
            $this->assertSame($expected($point->phpVersionId), $point->pcreVersion, (string) $point->phpVersionId);
        }

        $patch = self::runningMinor() + 5;
        $narrowed = $target->judgedRange(RegexParser::create($target->regexOptions()))->narrow(TargetConstraint::phpFrom($patch, self::runningMinor() + 100));
        $this->assertSame($patch, $narrowed[0]->phpVersionId);
        $this->assertSame(PcreTarget::runtime()->pcreVersion, $narrowed[0]->pcreVersion);
    }

    /**
     * The entry points that name their own settings, the Symfony and
     * Laravel bridges and the language server, follow the same rule.
     */
    #[Test]
    public function test_named_sources_follow_the_rule(): void
    {
        $project = $this->makeProject(['composer.json' => '{"config": {"platform": {"php": "8.2", "lib-pcre": "10.45"}}}']);

        $this->assertSame(PcreTarget::runtime()->pcreVersion, ProjectTarget::fromSources(['php_regex.php_version' => self::runningMinor()], [], null, [])->target()->pcreVersion);
        $this->assertSame('10.45', ProjectTarget::fromSources(['php_regex.php_version' => null], ['php_regex.pcre_version' => null], $project, [])->target()->pcreVersion);
        $this->assertSame('10.45', ProjectTarget::fromSources(['php_regex.php_version' => self::runningMinor()], [], $project, [])->target()->pcreVersion);
        $this->assertSame('10.39', ProjectTarget::fromSources([], ['php_regex.pcre_version' => '10.39'], $project, [])->target()->pcreVersion);
    }

    /**
     * A PHP below 7.3 an analyser read from composer.json is refused when
     * the file gives no PHP, a locale named beside it or not: the lib-pcre
     * or the locale read with it names no PHP.
     */
    #[Test]
    public function test_a_php_on_pcre1_read_from_composer_json_is_refused_whatever_else_is_named(): void
    {
        foreach ([[], ['phpRegex.locale' => 'C']] as $locale) {
            foreach (['{}', '{"config": {"platform": {"lib-pcre": "10.42"}}}'] as $composer) {
                try {
                    ProjectTarget::belowPcre2(70100, 'phpVersion', $this->makeProject(['composer.json' => $composer]), [], $locale);
                    $this->fail('A PHP on PCRE1 was judged.');
                } catch (UnsupportedTargetException $e) {
                    $this->assertStringStartsWith('phpVersion names PHP 7.1: ', $e->getMessage());
                }
            }
        }
    }

    /**
     * The x.y.0 release of the running PHP's minor, or x.y.1 when the
     * running PHP is x.y.0: a version of its minor that is not itself.
     */
    private static function runningMinor(): int
    {
        $minor = intdiv(\PHP_VERSION_ID, 100) * 100;

        return \PHP_VERSION_ID === $minor ? $minor + 1 : $minor;
    }

    private static function runningMinorLabel(): string
    {
        return ProjectTarget::phpLabel(self::runningMinor());
    }

    /**
     * A PHP on PCRE2 of another minor than the running PHP's.
     */
    private static function otherMinor(): int
    {
        return 802 === intdiv(\PHP_VERSION_ID, 100) ? 80300 : 80200;
    }
}
