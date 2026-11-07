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

namespace PHPRegex\Tests\Unit\Transpiler;

use PHPRegex\Parser\RegexParser;
use PHPRegex\Transpiler\Exception\TranspileException;
use PHPRegex\Transpiler\Target\JavaScript\CaptureSemantics;
use PHPRegex\Transpiler\Transpiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CaptureSemanticsWorkTest extends TestCase
{
    #[Test]
    #[DataProvider('provideSharedReferences')]
    public function test_shared_predecessors_complete(string $target, bool $empty): void
    {
        $body = $empty ? '()' : '(a)';
        for ($number = 1; $number < 28; ++$number) {
            $reference = '\\g{'.$number.'}';
            $body .= '('.$reference.($empty ? '' : '|').$reference.')';
        }
        $pattern = '/'.$body.'(?:\\g{28})+/';
        $this->assertSame($empty ? 1 : 0, preg_match($pattern, ''));

        $this->assertNotSame('', (new Transpiler())->transpile($pattern, $target)->pattern);
    }

    /**
     * @return iterable<string, array{target: string, empty: bool}>
     */
    public static function provideSharedReferences(): iterable
    {
        foreach (['javascript', 'html'] as $target) {
            foreach ([true, false] as $empty) {
                yield $target.($empty ? ' empty' : ' consuming') => ['target' => $target, 'empty' => $empty];
            }
        }
    }

    #[Test]
    public function test_cycle_dependent_results_are_not_reused(): void
    {
        $pattern = '/(\\2a)(\\1)/';
        $this->assertSame(0, preg_match($pattern, ''));
        $ast = RegexParser::create(['cache' => null])->parse($pattern);
        $analysis = new CaptureSemantics();
        (new \ReflectionMethod($analysis, 'number'))->invoke($analysis, $ast->pattern);
        $groups = (new \ReflectionProperty($analysis, 'groups'))->getValue($analysis);
        $matchesEmpty = new \ReflectionMethod($analysis, 'matchesEmpty');

        // Group 2 provisionally reads empty while group 1 is active, but
        // neither group can match empty when read independently.
        $this->assertFalse($matchesEmpty->invoke($analysis, $groups[1]));
        $this->assertFalse($matchesEmpty->invoke($analysis, $groups[2]));
    }

    #[Test]
    public function test_exhausted_work_budget_refuses_analysis(): void
    {
        $ast = RegexParser::create(['cache' => null])->parse('/a/');
        $analysis = new CaptureSemantics();
        $limit = (new \ReflectionClass($analysis))->getConstant('MAX_EMPTINESS_STEPS');
        (new \ReflectionProperty($analysis, 'emptinessSteps'))->setValue($analysis, $limit);

        $this->expectException(TranspileException::class);
        $this->expectExceptionMessage('Capture emptiness analysis exceeded its work limit.');
        (new \ReflectionMethod($analysis, 'matchesEmpty'))->invoke($analysis, $ast->pattern);
    }
}
