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
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A directory with no composer.json is the usual place for a command that
 * judges one pattern: the notice that says so is the lint's, which expects
 * a project, and is left out when the caller asks.
 */
final class ProjectTargetNoProjectTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    public function test_the_notice_of_a_missing_composer_json_can_be_left_out(): void
    {
        $directory = $this->makeProject([]);

        $target = ProjectTarget::resolve(null, null, [], $directory, []);

        $this->assertSame([\sprintf('No composer.json in %s: judging for the running PHP.', $directory)], $target->notices());
        $this->assertSame([], $target->notices(missingProject: false));
    }

    #[Test]
    public function test_every_other_notice_stays(): void
    {
        $directory = $this->makeProject(['composer.json' => '{"require": {"php": ">=8.1"']);

        $target = ProjectTarget::resolve(null, null, [], $directory, []);

        $this->assertSame(['composer.json is not a JSON object: judging for the running PHP.'], $target->notices(missingProject: false));
        $this->assertSame($target->notices(), $target->notices(missingProject: false));
    }

    #[Test]
    public function test_a_named_target_reads_no_project_and_says_nothing_of_it(): void
    {
        $target = ProjectTarget::resolve('>=7.0', null, [], $this->makeProject([]), []);

        $this->assertSame($target->notices(), $target->notices(missingProject: false));
        $this->assertNotSame([], $target->notices(), 'The 7.3 clip is said.');
    }
}
