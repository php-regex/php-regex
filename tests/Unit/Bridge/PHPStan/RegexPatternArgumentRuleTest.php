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

namespace PHPRegex\Tests\Unit\Bridge\PHPStan;

use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\VariadicPlaceholder;
use PHPRegex\PHPStan\RegexPatternArgumentRule;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class RegexPatternArgumentRuleTest extends TestCase
{
    #[Test]
    public function test_a_first_class_callable_passes_no_argument(): void
    {
        /** @var CollectedDataEmitter&DependencyTracker&NodeCallbackInvoker&Scope&Stub $scope */
        $scope = $this->createStub(Scope::class);
        $rule = new RegexPatternArgumentRule($this->createStub(ReflectionProvider::class));

        // matches(...): no argument to read, and getArgs() would throw.
        $node = new FuncCall(new Name('matches'), [new VariadicPlaceholder()]);

        $this->assertSame([], $rule->processNode($node, $scope));
    }

    #[Test]
    public function test_the_rule_reads_every_kind_of_call(): void
    {
        $rule = new RegexPatternArgumentRule($this->createStub(ReflectionProvider::class));

        $this->assertSame(CallLike::class, $rule->getNodeType());
    }
}
