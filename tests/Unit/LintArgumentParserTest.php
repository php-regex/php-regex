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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Linter\Config\LintArgumentParser;
use PHPRegex\Linter\Config\LintArguments;
use PHPUnit\Framework\TestCase;

final class LintArgumentParserTest extends TestCase
{
    public function test_argument_parser_returns_help_flag(): void
    {
        $parser = new LintArgumentParser();

        $result = $parser->parse(['--help']);

        $this->assertTrue($result->help);
        $this->assertNotInstanceOf(LintArguments::class, $result->arguments);
    }

    /**
     * A rule reads its setting under its short id, as regex.json writes it:
     * the full id on the command line names the same rule.
     */
    public function test_a_rule_named_by_its_full_id_is_stored_under_its_short_id(): void
    {
        $result = (new LintArgumentParser())->parse(['--disable-rule=regex.lint.group.redundant', '--enable-rule=regex.lint.charclass.single', '--disable-rule=flag.useless.s']);

        $this->assertInstanceOf(LintArguments::class, $result->arguments);
        $this->assertSame(['group.redundant' => false, 'charclass.single' => true, 'flag.useless.s' => false], $result->arguments->lintRules);
    }
}
