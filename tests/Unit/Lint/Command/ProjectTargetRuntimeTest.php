<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\Lint\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Lint\Command\LintConfigSchema;
use RegexParser\Lint\Command\ProjectTarget;
use RegexParser\PcreTarget;

/**
 * "runtime" names the PHP running the command and the PCRE2 it links, as
 * PHPStan's own phpVersion setting does: a project can lint for its floor
 * and still ask, for one run, what the engine at hand says.
 */
final class ProjectTargetRuntimeTest extends TestCase
{
    #[Test]
    public function test_the_option_names_the_running_engine(): void
    {
        $target = ProjectTarget::resolve('runtime', null, [], __DIR__, []);

        $this->assertEquals(PcreTarget::runtime(), $target->target());
        $this->assertSame('--php-version (running PHP)', $target->source());
    }

    #[Test]
    public function test_regex_json_names_the_running_engine(): void
    {
        $target = ProjectTarget::resolve(null, null, ['phpVersion' => 'Runtime'], __DIR__, []);

        $this->assertEquals(PcreTarget::runtime(), $target->target());
        $this->assertSame('regex.json (running PHP)', $target->source());
    }

    #[Test]
    public function test_a_pcre_release_given_beside_it_wins(): void
    {
        $target = ProjectTarget::resolve('runtime', '10.42', [], __DIR__, []);

        $this->assertSame(\PHP_VERSION_ID, $target->target()->phpVersionId);
        $this->assertSame('10.42', $target->target()->pcreVersion);
    }

    #[Test]
    public function test_the_schema_accepts_it(): void
    {
        $definition = LintConfigSchema::definition();
        $properties = $definition['properties'] ?? null;
        $this->assertIsArray($properties);
        $phpVersion = $properties['phpVersion'] ?? null;
        $this->assertIsArray($phpVersion);
        $pattern = $phpVersion['pattern'] ?? null;
        $this->assertIsString($pattern);

        $this->assertSame(1, preg_match('/'.$pattern.'/', 'runtime'));
        $this->assertSame(1, preg_match('/'.$pattern.'/', '8.3'));
    }
}
