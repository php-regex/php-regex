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

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\LintRuleRegistry;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The linter a caller builds takes the rules to enable and gives the
 * issues: the rule registry is the library's own, and the issues read one
 * way.
 */
final class PatternLinterSurfaceTest extends TestCase
{
    #[Test]
    public function test_the_constructor_takes_the_enabled_rules_only(): void
    {
        $constructor = new \ReflectionMethod(PatternLinter::class, '__construct');

        $this->assertSame(['enabledRules'], array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $constructor->getParameters()));
        $this->assertStringContainsString('@internal', (string) (new \ReflectionMethod(PatternLinter::class, 'withRegistry'))->getDocComment());
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
