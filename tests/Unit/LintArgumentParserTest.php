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

use PHPRegex\Cli\Command\LintArgumentParser;
use PHPRegex\Linter\Config\LintArguments;
use PHPRegex\Linter\Config\LintConfigSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
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

    /**
     * An id regex.json refuses is refused on the command line too, naming
     * the option and the id, instead of turning nothing on or off.
     */
    #[Test]
    #[DataProvider('provideUnknownRuleIds')]
    public function test_an_unknown_rule_id_is_a_usage_error(string $argument, string $error): void
    {
        $result = (new LintArgumentParser())->parse(['src', $argument]);

        $this->assertNull($result->arguments);
        $this->assertSame($error, $result->error);
    }

    /**
     * @return iterable<string, array{argument: string, error: string}>
     */
    public static function provideUnknownRuleIds(): iterable
    {
        yield 'a misspelt id disabled' => ['argument' => '--disable-rule=quantifer.nested', 'error' => 'Unknown lint rule "quantifer.nested" in --disable-rule. Did you mean "quantifier.nested"?'];
        yield 'a misspelt full id' => ['argument' => '--disable-rule=regex.lint.quantifer.nested', 'error' => 'Unknown lint rule "regex.lint.quantifer.nested" in --disable-rule. Did you mean "regex.lint.quantifier.nested"?'];
        yield 'an unknown id enabled' => ['argument' => '--enable-rule=nope.nope', 'error' => 'Unknown lint rule "nope.nope" in --enable-rule.'];
        yield 'an unknown full id' => ['argument' => '--disable-rule=regex.lint.nope', 'error' => 'Unknown lint rule "regex.lint.nope" in --disable-rule.'];
        yield 'an empty id' => ['argument' => '--enable-rule=', 'error' => 'Unknown lint rule "" in --enable-rule.'];
    }

    /**
     * Every id regex.json accepts under checks.lint.rules, the issues of an
     * analysis included, is accepted on the command line.
     */
    #[Test]
    public function test_every_rule_id_regex_json_accepts_is_accepted(): void
    {
        $definition = LintConfigSchema::definition();
        $this->assertIsArray($definition['$defs']);
        $this->assertIsArray($definition['$defs']['checksLint']);
        $this->assertIsArray($definition['$defs']['checksLint']['properties']);
        $this->assertIsArray($definition['$defs']['checksLint']['properties']['rules']);
        $this->assertIsArray($definition['$defs']['checksLint']['properties']['rules']['properties']);
        $ids = array_map(strval(...), array_keys($definition['$defs']['checksLint']['properties']['rules']['properties']));
        $this->assertContains('redos.search', $ids);

        $arguments = array_map(static fn (string $id): string => '--disable-rule='.$id, $ids);
        $result = (new LintArgumentParser())->parse($arguments);

        $this->assertNull($result->error);
        $this->assertInstanceOf(LintArguments::class, $result->arguments);
        $this->assertSame(array_fill_keys($ids, false), $result->arguments->lintRules);
    }
}
