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
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\PcreTarget;
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

        $this->assertSame(['php' => '8.3', 'pcre' => '10.42', 'source' => 'php_regex.php_version', 'range' => [['php' => '8.3', 'pcre' => '10.42']]], $resolved->toArray());
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
}
