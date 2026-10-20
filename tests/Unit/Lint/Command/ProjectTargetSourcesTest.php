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
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The same chain for every entry point that reads project files, each
 * naming its own settings: the first set source wins, in the order given,
 * before composer.json and the running PHP.
 */
final class ProjectTargetSourcesTest extends TestCase
{
    use InstalledLocales;
    use PhpUnitForwardCompat;
    use TemporaryProject;

    #[Test]
    public function test_the_first_set_source_wins_and_is_named(): void
    {
        $directory = $this->makeProject(['composer.json' => '{"require": {"php": ">=8.4"}}']);

        $resolved = ProjectTarget::fromSources(
            ['initializationOptions' => null, 'regex.json' => '8.2'],
            ['initializationOptions' => null, 'regex.json' => null],
            $directory,
            [],
        );

        $this->assertSame(80200, $resolved->target()->phpVersionId);
        $this->assertSame('10.40', $resolved->target()->pcreVersion);
        $this->assertSame('regex.json', $resolved->source());
    }

    #[Test]
    public function test_a_bridge_setting_is_named_as_the_bridge_names_it(): void
    {
        $resolved = ProjectTarget::fromSources(
            ['php_regex.php_version' => 80300],
            ['php_regex.pcre_version' => null],
            $this->makeProject(),
            [],
        );

        $this->assertSame(['php' => '8.3', 'pcre' => '10.42', 'locale' => 'C', 'source' => 'php_regex.php_version', 'range' => [['php' => '8.3', 'pcre' => '10.42']]], $resolved->toArray());
    }

    #[Test]
    public function test_a_pcre_release_alone_is_named_after_the_php_source(): void
    {
        $resolved = ProjectTarget::fromSources(
            ['config php-regex.php_version' => null],
            ['config php-regex.pcre_version' => '10.42'],
            null,
            [],
        );

        $this->assertSame(\PHP_VERSION_ID, $resolved->target()->phpVersionId);
        $this->assertSame('10.42', $resolved->target()->pcreVersion);
        $this->assertSame('running PHP; config php-regex.pcre_version', $resolved->source());
    }

    #[Test]
    public function test_without_a_project_directory_the_running_php_is_judged_without_a_notice(): void
    {
        $resolved = ProjectTarget::fromSources([], [], null, []);

        $this->assertEquals(PcreTarget::runtime(), $resolved->target());
        $this->assertSame('running PHP', $resolved->source());
        $this->assertSame([], $resolved->notices());
    }

    #[Test]
    public function test_an_unreadable_value_is_refused(): void
    {
        $this->expectException(InvalidRegexOptionException::class);

        ProjectTarget::fromSources(['php_regex.php_version' => 'eight'], [], null, []);
    }

    /**
     * The locale is the first set source, after the PHP's and the PCRE2's
     * in the source named; every version of the range, every parser of it
     * and every version a guard keeps run under it.
     */
    #[Test]
    public function test_the_first_set_locale_wins_and_every_version_keeps_it(): void
    {
        $this->requiresLocale('fr_FR.ISO8859-1', 'The locale of a project target');
        $directory = $this->makeProject(['composer.json' => '{"require": {"php": "^8.2"}}']);

        $resolved = ProjectTarget::fromSources([], [], $directory, [], ['--locale' => null, 'regex.json' => 'fr_FR.ISO8859-1']);

        $this->assertSame('fr_FR.ISO8859-1', $resolved->target()->locale);
        $this->assertSame('composer.json require.php; regex.json', $resolved->source());
        $this->assertSame('fr_FR.ISO8859-1', $resolved->regexOptions()['locale']);
        $this->assertSame('PHP 8.2, PCRE2 10.40, locale fr_FR.ISO8859-1', $resolved->label());
        $this->assertSame('fr_FR.ISO8859-1', $resolved->toArray()['locale']);
        foreach ($resolved->range() as $target) {
            $this->assertSame('fr_FR.ISO8859-1', $target->locale);
        }

        $floor = RegexParser::create($resolved->regexOptions());
        foreach ($resolved->rangeParsers($floor) as $parser) {
            $this->assertSame('fr_FR.ISO8859-1', $parser->target()->locale);
            $this->assertSame($floor->getCache(), $parser->getCache());
        }

        $judged = $resolved->judgedRange($floor);
        foreach ($judged->narrow(TargetConstraint::phpFrom(80300, null)) as $target) {
            $this->assertSame('fr_FR.ISO8859-1', $target->locale);
            $this->assertSame('fr_FR.ISO8859-1', $judged->parserFor($target)->target()->locale);
        }
    }

    #[Test]
    public function test_a_locale_named_where_the_php_is_is_named_once(): void
    {
        $resolved = ProjectTarget::fromSources(['regex.json' => '8.4'], [], null, [], ['regex.json' => 'C']);

        $this->assertSame('regex.json', $resolved->source());
        $this->assertSame('PHP 8.4, PCRE2 10.44', $resolved->label());
    }

    #[Test]
    public function test_a_locale_the_machine_cannot_set_is_refused(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains('"locale" names "xx_YY.NOWHERE"');

        ProjectTarget::fromSources([], [], null, [], ['regex.json' => 'xx_YY.NOWHERE']);
    }
}
