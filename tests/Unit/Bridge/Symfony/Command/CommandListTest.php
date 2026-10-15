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

use PHPRegex\Symfony\Command\RoutesCommand;
use PHPRegex\Symfony\Command\SecurityCommand;
use PHPRegex\Symfony\Routing\RouteConflictAnalyzer;
use PHPRegex\Symfony\Security\SecurityAccessControlAnalyzer;
use PHPRegex\Symfony\Security\SecurityConfigExtractor;
use PHPRegex\Symfony\Security\SecurityFirewallAnalyzer;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every command of the bridge is listed by bin/console list: regex:routes
 * and regex:security are documented commands, not hidden ones.
 */
final class CommandListTest extends TestCase
{
    #[Test]
    public function test_routes_is_listed(): void
    {
        $this->assertFalse((new RoutesCommand(new RouteConflictAnalyzer(Regex::create())))->isHidden());
    }

    #[Test]
    public function test_security_is_listed(): void
    {
        $command = new SecurityCommand(
            new SecurityConfigExtractor(),
            new SecurityAccessControlAnalyzer(Regex::create()),
            new SecurityFirewallAnalyzer(Regex::create()),
        );

        $this->assertFalse($command->isHidden());
    }

    #[Test]
    public function test_no_command_of_the_bridge_is_hidden(): void
    {
        foreach (glob(\dirname(__DIR__, 5).'/src/Symfony/Command/*Command.php') ?: [] as $file) {
            $this->assertStringNotContainsString('setHidden(', (string) file_get_contents($file), basename($file));
            $this->assertStringNotContainsString('hidden:', (string) file_get_contents($file), basename($file));
        }
    }
}
