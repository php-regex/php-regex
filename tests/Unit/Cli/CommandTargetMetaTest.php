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

use PhpRegex\Cli\Command\AbstractCommand;
use PhpRegex\Cli\GlobalOptions;
use PhpRegex\Cli\Input;
use PhpRegex\Cli\Output;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every command's banner names the PHP version and the PCRE2 release asked
 * for on the command line.
 */
final class CommandTargetMetaTest extends TestCase
{
    #[Test]
    public function test_the_banner_names_the_target_asked_for(): void
    {
        $meta = $this->targetMeta(new GlobalOptions(false, false, false, true, '8.4', null, '10.42'));

        $this->assertSame(['Target PHP' => 'PHP 8.4', 'Target PCRE2' => 'PCRE2 10.42'], $meta);
    }

    #[Test]
    public function test_the_banner_names_nothing_without_a_target(): void
    {
        $this->assertSame([], $this->targetMeta(new GlobalOptions(false, false, false, true, null, null)));
    }

    /**
     * @return array<string, string>
     */
    private function targetMeta(GlobalOptions $options): array
    {
        $command = new class extends AbstractCommand {
            public function getName(): string
            {
                return 'probe';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getDescription(): string
            {
                return '';
            }

            public function run(Input $input, Output $output): int
            {
                return 0;
            }

            /**
             * @return array<string, string>
             */
            public function meta(Input $input, Output $output): array
            {
                return $this->targetMeta($input, $output);
            }
        };

        return $command->meta(new Input('probe', [], $options, []), new Output(false, false));
    }
}
