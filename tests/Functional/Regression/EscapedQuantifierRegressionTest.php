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

namespace PhpRegex\Tests\Functional\Regression;

use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class EscapedQuantifierRegressionTest extends TestCase
{
    public function test_escaped_literal_plus(): void
    {
        // Test that escaped + is preserved
        $this->assertSame('#^application/(?:\w+\+)+json$#i', $this->compile('#^application/(?:\w+\+)+json$#i'));
    }

    public function test_escaped_literal_star(): void
    {
        // Test that escaped * is preserved
        $this->assertSame('/1 \* 1 = 1/', $this->compile('/1 \* 1 = 1/'));
    }

    public function test_escaped_literal_dot(): void
    {
        // Test that escaped . is preserved
        $this->assertSame('/file\.txt/', $this->compile('/file\.txt/'));
    }

    public function test_escaped_literal_question(): void
    {
        // Test that escaped ? is preserved
        $this->assertSame('/user\?/', $this->compile('/user\?/'));
    }

    private function compile(string $pattern): string
    {
        $regex = Regex::create();
        $ast = $regex->parse($pattern);
        $visitor = new PatternPrinter();

        return $ast->accept($visitor);
    }
}
