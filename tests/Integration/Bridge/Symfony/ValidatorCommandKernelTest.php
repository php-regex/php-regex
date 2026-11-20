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
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * regex:validator in an application: the validator service gives the
 * metadata, attributes included, the bundle hands it the mapping files,
 * and the project's src/ gives the classes.
 */
final class ValidatorCommandKernelTest extends TestCase
{
    use TemporaryProject;

    private const PROJECT = __DIR__.'/../../../Fixtures/Symfony/ValidatorAudit';

    private const MAPPING = __DIR__.'/../../../Fixtures/Symfony/ValidatorMapping/config/validator/customer.yaml';

    #[Test]
    public function test_regex_validator_audits_the_project_classes_and_the_mapped_ones(): void
    {
        $kernel = new FrameworkKernel((string) realpath(self::PROJECT), $this->makeProject(), framework: ['validation' => ['mapping' => ['paths' => [(string) realpath(self::MAPPING)]]]]);

        try {
            $tester = new CommandTester((new Application($kernel))->find('regex:validator'));
            $status = $tester->execute(['--format' => 'json']);
        } finally {
            $kernel->shutdown();
        }

        $this->assertSame(Command::FAILURE, $status);
        $section = JsonContract::asArray(JsonContract::asArray(JsonContract::decodeDocument($tester->getDisplay())['sections'] ?? null)[0] ?? null);
        // The findings of Signup, from src/; the patterns of Customer, from
        // the mapping file, do not compile: the comparison says so.
        $this->assertCount(6, JsonContract::asArray($section['issues'] ?? null));
        $this->assertCount(3, JsonContract::asArray($section['warnings'] ?? null));
        $this->assertStringContainsString('Customer::$name /[a-z/', $tester->getDisplay());
    }

    #[Test]
    public function test_regex_analyze_runs_the_validator_section(): void
    {
        $kernel = new FrameworkKernel((string) realpath(self::PROJECT), $this->makeProject());

        try {
            $tester = new CommandTester((new Application($kernel))->find('regex:analyze'));
            $status = $tester->execute(['--only' => ['validator'], '--fail-on' => ['validator'], '--format' => 'json']);
        } finally {
            $kernel->shutdown();
        }

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('"kind": "validator"', $tester->getDisplay());
    }

    #[Before]
    protected function rememberTheWorkingDirectory(): void
    {
        $this->rememberWorkingDirectory();
    }

    #[After]
    protected function removeTheProjects(): void
    {
        $this->removeTemporaryProjects();
    }
}
