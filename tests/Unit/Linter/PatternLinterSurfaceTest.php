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

namespace PHPRegex\Tests\Unit\Linter;

use PHPRegex\Linter\Exception\UnknownLintRuleException;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\LintRuleRegistry;
use PHPRegex\Linter\Rule\RuleCatalog;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The linter a caller builds takes the rules to enable and gives the
 * issues: the rule registry is the library's own, and the issues read one
 * way.
 */
final class PatternLinterSurfaceTest extends TestCase
{
    /**
     * The target comes after the rules, in the order withDfaCache() takes
     * them: the questions a rule asks are read for it.
     */
    #[Test]
    public function test_the_constructor_takes_the_enabled_rules_and_the_target(): void
    {
        $constructor = new \ReflectionMethod(PatternLinter::class, '__construct');

        $this->assertSame(['enabledRules', 'target'], array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $constructor->getParameters()));
        $this->assertSame('?'.PcreTarget::class, (string) $constructor->getParameters()[1]->getType());
        $this->assertNull($constructor->getParameters()[1]->getDefaultValue());
        $this->assertStringContainsString('@internal', (string) (new \ReflectionMethod(PatternLinter::class, 'withRegistry'))->getDocComment());
    }

    /**
     * How a rules map turns a rule on or off is the catalogue's to say, not
     * a public method of the linter.
     */
    #[Test]
    public function test_the_linter_exposes_no_rule_defaults(): void
    {
        $this->assertFalse((new \ReflectionClass(PatternLinter::class))->hasMethod('enables'));
    }

    /**
     * A rules map naming a rule the linter does not run is refused, the
     * nearest rule named, in the spelling it was written in: a typo used to
     * turn nothing off without a word.
     */
    #[Test]
    #[DataProvider('provideUnknownRules')]
    public function test_the_constructor_refuses_a_rule_it_does_not_run(string $id, string $message): void
    {
        try {
            new PatternLinter(['quantifier.useless' => true, $id => false]);
            $this->fail('No exception for '.$id.'.');
        } catch (UnknownLintRuleException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame($id, $e->ruleId);
        }
    }

    /**
     * @return iterable<string, array{id: string, message: string}>
     */
    public static function provideUnknownRules(): iterable
    {
        yield 'a typo' => ['id' => 'quantifier.nestd', 'message' => 'Unknown lint rule "quantifier.nestd". Did you mean "quantifier.nested"?'];
        yield 'a typo in full' => ['id' => 'regex.lint.quantifier.nestd', 'message' => 'Unknown lint rule "regex.lint.quantifier.nestd". Did you mean "regex.lint.quantifier.nested"?'];
        yield 'nothing near' => ['id' => 'no.such.rule', 'message' => 'Unknown lint rule "no.such.rule".'];
        yield 'an issue no rules map turns off' => ['id' => 'redos', 'message' => 'Unknown lint rule "redos".'];
    }

    /**
     * The exception is the library's own, for a value of the wrong kind.
     */
    #[Test]
    public function test_the_exception_is_the_librarys_own(): void
    {
        $class = new \ReflectionClass(UnknownLintRuleException::class);

        $this->assertTrue($class->implementsInterface(ExceptionInterface::class));
        $this->assertTrue($class->isSubclassOf(\InvalidArgumentException::class));
        $this->assertTrue($class->isFinal());
    }

    /**
     * The map takes each rule, and each analysis issue regex.json turns
     * off, in either spelling.
     */
    #[Test]
    public function test_the_constructor_takes_every_rule_in_either_spelling(): void
    {
        $short = new PatternLinter(array_fill_keys(RuleCatalog::configurableIds(), false));
        $full = new PatternLinter(array_fill_keys(array_map(static fn (string $id): string => 'regex.lint.'.$id, RuleCatalog::configurableIds()), false));
        Regex::create()->parse('/a{1}/')->accept($short);
        Regex::create()->parse('/a{1}/')->accept($full);

        $this->assertSame([], $short->getIssues());
        $this->assertSame([], $full->getIssues());
    }

    /**
     * A registry of other rules is configured by their identifiers too, and
     * refuses an identifier neither it nor the catalogue knows.
     */
    #[Test]
    public function test_a_registry_of_other_rules_takes_their_identifiers(): void
    {
        $registry = new LintRuleRegistry();

        $linter = PatternLinter::withRegistry(['quantifier.useless' => false, 'regex.lint.flag.redundant' => false], $registry);
        Regex::create()->parse('/a{1}/')->accept($linter);
        $this->assertSame([], $linter->getIssues());

        $this->expectException(UnknownLintRuleException::class);
        PatternLinter::withRegistry(['quantifier.nestd' => false], $registry);
    }

    #[Test]
    public function test_the_issues_read_one_way(): void
    {
        $linter = PatternLinter::withRegistry([], new LintRuleRegistry());
        Regex::create()->parse('/a{1}/')->accept($linter);

        $this->assertNotSame([], $linter->getIssues());
        $this->assertFalse((new \ReflectionClass(PatternLinter::class))->hasMethod('getWarnings'));
        $this->assertFalse((new \ReflectionClass(PatternLinter::class))->hasMethod('getFullPattern'));
    }
}
