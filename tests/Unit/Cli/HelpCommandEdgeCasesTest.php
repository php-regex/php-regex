<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Cli;

use PhpRegex\Cli\Command\HelpCommand;
use PHPUnit\Framework\TestCase;

final class HelpCommandEdgeCasesTest extends TestCase
{
    public function test_resolve_invocation_defaults_to_regex(): void
    {
        $command = new HelpCommand();
        $method = (new \ReflectionClass($command))->getMethod('resolveInvocation');

        $_SERVER['argv'] = [''];

        $this->assertSame('regex', $method->invoke($command));
    }
}
