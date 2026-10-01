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

namespace PHPRegex\Tests\Unit\Cli;

use PHPRegex\Cli\GlobalOptionsParser;
use PHPUnit\Framework\TestCase;

final class GlobalOptionsParserTest extends TestCase
{
    public function test_parse_collects_global_options_and_args(): void
    {
        $parser = new GlobalOptionsParser();

        $parsed = $parser->parse(['--ansi', '--php-version', '8.2', 'lint', 'src']);

        $this->assertTrue($parsed->options->ansi);
        $this->assertSame('8.2', $parsed->options->phpVersion);
        $this->assertFalse($parsed->options->quiet);
        $this->assertFalse($parsed->options->help);
        $this->assertTrue($parsed->options->visuals);
        $this->assertNull($parsed->options->error);
        $this->assertSame(['lint', 'src'], $parsed->args);
    }

    public function test_parse_reports_missing_php_version(): void
    {
        $parser = new GlobalOptionsParser();

        $parsed = $parser->parse(['--php-version']);

        $this->assertSame('Missing value for --php-version.', $parsed->options->error);
    }

    public function test_parse_supports_inline_php_version(): void
    {
        $parser = new GlobalOptionsParser();

        $parsed = $parser->parse(['--no-ansi', '--php-version=8.3', '--help']);

        $this->assertFalse($parsed->options->ansi);
        $this->assertSame('8.3', $parsed->options->phpVersion);
        $this->assertTrue($parsed->options->help);
    }

    public function test_parse_collects_the_pcre_version(): void
    {
        $parser = new GlobalOptionsParser();

        $this->assertSame('10.42', $parser->parse(['--pcre-version', '10.42', 'lint'])->options->pcreVersion);
        $this->assertSame('10.44', $parser->parse(['--pcre-version=10.44'])->options->pcreVersion);
        $this->assertNull($parser->parse(['lint'])->options->pcreVersion);
        $this->assertSame('Missing value for --pcre-version.', $parser->parse(['--pcre-version'])->options->error);
    }

    public function test_parse_disables_visuals(): void
    {
        $parser = new GlobalOptionsParser();

        $parsed = $parser->parse(['--no-visuals', 'help']);

        $this->assertFalse($parsed->options->visuals);
    }
}
