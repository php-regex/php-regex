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

namespace PHPRegex\Tests\Integration\Bridge\Symfony;

use PHPRegex\Tests\Support\FrameworkKernel;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The bundle's configuration, as config:dump-reference prints it: every
 * key, its default and its help, under the php_regex alias. A change to
 * the tree shows here first.
 */
final class ConfigReferenceTest extends TestCase
{
    use TemporaryProject;

    private const REFERENCE = __DIR__.'/../../../Fixtures/Symfony/config_reference.yaml';

    #[Test]
    public function test_the_configuration_reference_is_the_documented_one(): void
    {
        $this->assertSame((string) file_get_contents(self::REFERENCE), $this->dump('php_regex'));
    }

    #[Test]
    public function test_the_bundle_answers_to_its_name_as_to_its_alias(): void
    {
        // The first line names what was asked for.
        $this->assertSame(strstr($this->dump('php_regex'), "\n"), strstr($this->dump('PHPRegexSymfonyBundle'), "\n"));
    }

    private function dump(string $name): string
    {
        $kernel = new FrameworkKernel($this->makeProject(), $this->makeProject());

        try {
            $tester = new CommandTester((new Application($kernel))->find('config:dump-reference'));
            $status = $tester->execute(['name' => $name]);
        } finally {
            $kernel->shutdown();
        }

        $this->assertSame(Command::SUCCESS, $status);

        return $tester->getDisplay(true);
    }
}
