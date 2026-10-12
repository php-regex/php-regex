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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Command;

use PHPRegex\Symfony\Command\CompareCommand;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each console command answers to its regex: name alone: a second name
 * would be one more name the 2.x promise keeps.
 */
final class CommandNamesTest extends TestCase
{
    #[Test]
    public function test_compare_has_no_alias(): void
    {
        $command = new CompareCommand(Regex::create());

        $this->assertSame('regex:compare', $command->getName());
        $this->assertSame([], $command->getAliases());
    }

    #[Test]
    public function test_no_command_of_the_bridge_declares_an_alias(): void
    {
        foreach (glob(\dirname(__DIR__, 5).'/src/Symfony/Command/*Command.php') ?: [] as $file) {
            $this->assertStringNotContainsString('setAliases(', (string) file_get_contents($file), basename($file));
            $this->assertStringNotContainsString('aliases:', (string) file_get_contents($file), basename($file));
        }
    }
}
