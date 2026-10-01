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

namespace PHPRegex\Tests\Integration;

use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class GroupNumberingValidationTest extends TestCase
{
    private Regex $regexService;

    protected function setUp(): void
    {
        $this->regexService = Regex::create();
    }

    public function test_forward_numeric_backreference_is_valid(): void
    {
        $result = $this->regexService->validate('/\1(a)/');
        $this->assertTrue($result->isValid);
    }

    public function test_forward_named_backreference_is_valid(): void
    {
        $result = $this->regexService->validate('/\k<name>(?<name>a)/');
        $this->assertTrue($result->isValid);
    }

    public function test_forward_relative_backreference_is_valid(): void
    {
        $result = $this->regexService->validate('/\g{+1}(a)/');
        $this->assertTrue($result->isValid);
    }

    public function test_forward_subroutine_is_valid(): void
    {
        $result = $this->regexService->validate('/(?1)(a)/');
        $this->assertTrue($result->isValid);
    }

    public function test_forward_named_subroutine_is_valid(): void
    {
        $result = $this->regexService->validate('/(?&name)(?<name>a)/');
        $this->assertTrue($result->isValid);
    }

    public function test_branch_reset_rejects_nonexistent_group(): void
    {
        $result = $this->regexService->validate('/(?|(a)|(b))\2/');
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('Backreference', (string) $result->error);
    }

    public function test_branch_reset_allows_shared_group_number(): void
    {
        $result = $this->regexService->validate('/(?|(a)|(b))\1/');
        $this->assertTrue($result->isValid);
    }

    public function test_backreference_g0_is_invalid(): void
    {
        $result = $this->regexService->validate('/\g{0}(a)/');
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('g{0}', (string) $result->error);
    }
}
