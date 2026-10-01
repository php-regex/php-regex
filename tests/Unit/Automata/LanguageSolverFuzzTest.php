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

namespace PhpRegex\Tests\Unit\Automata;

use PhpRegex\Automata\LanguageSolver;
use PhpRegex\Automata\Options\MatchMode;
use PhpRegex\Automata\Options\SolverOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LanguageSolverFuzzTest extends TestCase
{
    #[Test]
    public function test_intersection_examples_match_literal_patterns(): void
    {
        $solver = new LanguageSolver();
        $options = new SolverOptions(matchMode: MatchMode::Full);

        for ($i = 0; $i < 50; $i++) {
            $left = $this->randomLiteral();
            $right = $this->randomLiteral();

            $pattern1 = '/'.\preg_quote($left, '/').'/';
            $pattern2 = '/'.\preg_quote($right, '/').'/';

            $result = $solver->intersection($pattern1, $pattern2, $options);

            if ($left === $right) {
                $this->assertFalse($result->isEmpty);
                $this->assertSame($left, $result->example);
                $this->assertMatchesRegularExpression($pattern1, $result->example ?? '');
                $this->assertMatchesRegularExpression($pattern2, $result->example ?? '');
            } else {
                $this->assertTrue($result->isEmpty);
            }
        }
    }

    private function randomLiteral(): string
    {
        $length = \random_int(1, 3);
        $chars = '';
        for ($i = 0; $i < $length; $i++) {
            $chars .= \random_int(0, 1) ? 'a' : 'b';
        }

        return $chars;
    }
}
