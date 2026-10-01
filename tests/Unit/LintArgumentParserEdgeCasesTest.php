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

namespace PhpRegex\Tests\Unit;

use PhpRegex\Linter\Config\LintArgumentParser;
use PhpRegex\Linter\Config\LintArguments;
use PhpRegex\Linter\Formatter\OutputConfiguration;
use PHPUnit\Framework\TestCase;

final class LintArgumentParserEdgeCasesTest extends TestCase
{
    public function test_verbose_flag_sets_verbosity(): void
    {
        $parser = new LintArgumentParser();
        $result = $parser->parse(['--verbose']);

        $this->assertInstanceOf(LintArguments::class, $result->arguments);
        $this->assertSame(OutputConfiguration::VERBOSITY_VERBOSE, $result->arguments->verbosity);
    }

    public function test_jobs_must_be_positive(): void
    {
        $parser = new LintArgumentParser();
        $result = $parser->parse(['--jobs', '0']);

        $this->assertNotInstanceOf(LintArguments::class, $result->arguments);
        $this->assertSame('The --jobs value must be a positive integer.', $result->error);
    }
}
