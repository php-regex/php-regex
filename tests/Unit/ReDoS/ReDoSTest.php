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

namespace PhpRegex\Tests\Unit\ReDoS;

use PhpRegex\Redos\RedosAnalyzer;
use PhpRegex\Redos\RedosSeverity;
use PHPUnit\Framework\TestCase;

final class ReDoSTest extends TestCase
{
    /**
     * Test ReDoS analysis reduces severity for recursion with possessives.
     */
    public function test_redos_recursion_with_possessives_reduced_severity(): void
    {
        $analyzer = new RedosAnalyzer();
        // Pattern with recursion and possessives: \{args\.((?:[^\{\}\}]++|(?R))*)\}
        $result = $analyzer->analyze('/\{args\.((?:[^\{\}\}]++|(?R))*)\}/');

        // Should be MEDIUM, not CRITICAL, due to possessives and recursion handling
        $this->assertSame(RedosSeverity::MEDIUM, $result->severity);
    }

    public function test_redos_symfony_route_requirement_is_not_high(): void
    {
        $analyzer = new RedosAnalyzer();
        $pattern = '{(\\s++|\\((?:[^()]*+|(?R))*\\)(?: *: *[^ ]++)?|<(?:[^<>]*+|(?R))*>|\\{(?:[^{}]*+|(?R))*\\})}';
        $result = $analyzer->analyze($pattern);

        $this->assertContains(
            $result->severity,
            [RedosSeverity::SAFE, RedosSeverity::LOW, RedosSeverity::MEDIUM],
            'Expected ReDoS severity to be SAFE, LOW, or MEDIUM for Symfony route requirements.',
        );
    }

    /**
     * Test ReDoS analysis on standard patterns.
     */
    public function test_redos_nested_quantifiers_critical(): void
    {
        $analyzer = new RedosAnalyzer();
        $result = $analyzer->analyze('/(\\D+)*[12]/');

        $this->assertSame(RedosSeverity::CRITICAL, $result->severity);
    }
}
