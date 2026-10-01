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

namespace PhpRegex\Tests\Functional\Cli;

use PhpRegex\Cli\GlobalOptionsParser;
use PHPUnit\Framework\TestCase;

final class GlobalOptionsParserTest extends TestCase
{
    public function test_parse_sets_options_and_preserves_args(): void
    {
        $parser = new GlobalOptionsParser();

        $result = $parser->parse([
            '--quiet',
            '--ansi',
            '--no-visuals',
            '--php-version',
            '8.2',
            'parse',
            '/a+/',
        ]);

        $options = $result->options;

        $this->assertTrue($options->quiet);
        $this->assertTrue($options->ansi);
        $this->assertFalse($options->help);
        $this->assertFalse($options->visuals);
        $this->assertSame('8.2', $options->phpVersion);
        $this->assertNull($options->error);

        $this->assertSame(['parse', '/a+/'], $result->args);
    }

    public function test_parse_supports_inline_php_version_flag(): void
    {
        $parser = new GlobalOptionsParser();

        $result = $parser->parse(['--php-version=8.3', 'highlight', '/abc/']);

        $this->assertSame('8.3', $result->options->phpVersion);
        $this->assertSame(['highlight', '/abc/'], $result->args);
    }

    public function test_parse_reports_missing_php_version_value(): void
    {
        $parser = new GlobalOptionsParser();

        $result = $parser->parse(['--php-version', '--help']);

        $this->assertSame('Missing value for --php-version.', $result->options->error);
    }

    public function test_parse_handles_help_and_ansi_flags(): void
    {
        $parser = new GlobalOptionsParser();

        $result = $parser->parse(['--help', '--no-ansi']);

        $this->assertTrue($result->options->help);
        $this->assertFalse($result->options->ansi);
    }
}
