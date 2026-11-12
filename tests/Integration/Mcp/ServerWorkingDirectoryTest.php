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

namespace PHPRegex\Tests\Integration\Mcp;

use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Mcp\ToolCatalog;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The server's target is the call's, then its own flag, then the running
 * PHP: unlike the CLI's lint, it reads no file of its working directory.
 */
final class ServerWorkingDirectoryTest extends TestCase
{
    use TemporaryProject;

    /**
     * A regex.json and a composer.json in the server's working directory
     * name a target the CLI would use: the server reads neither.
     */
    #[Test]
    public function test_the_server_reads_no_file_from_its_working_directory(): void
    {
        $this->enterProject([
            'composer.json' => '{"name": "acme/app", "require": {"php": ">=8.1"}}',
            'regex.json' => '{"phpVersion": "8.2", "pcreVersion": "10.40"}',
        ]);

        $result = ToolCatalog::v1()->get('regex_validate')->tool->execute(['pattern' => '/a/']);

        $this->assertFalse($result->isError, $result->text);
        $this->assertSame(ProjectTarget::fromSources([], [], null, [])->toArray(), $result->structured['target'] ?? null);
        $this->assertCount(1, JsonContract::asArray($result->structured['range'] ?? null));
    }
}
