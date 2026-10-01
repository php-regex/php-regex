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

namespace PhpRegex\Tests\Unit\Lint;

use PhpRegex\Linter\LintRequest;
use PHPUnit\Framework\TestCase;

final class RegexLintRequestTest extends TestCase
{
    public function test_is_source_enabled_returns_true_when_source_not_disabled(): void
    {
        $request = new LintRequest(['.'], [], 0, [], true, true, true);

        $this->assertTrue($request->isSourceEnabled('any_source'));
    }

    public function test_is_source_enabled_returns_true_for_specific_source_when_not_in_disabled_list(): void
    {
        $request = new LintRequest(['.'], [], 0, ['other_source'], true, true, true);

        $this->assertTrue($request->isSourceEnabled('my_source'));
    }

    public function test_is_source_enabled_returns_false_when_source_disabled(): void
    {
        $request = new LintRequest(['.'], [], 0, ['my_source'], true, true, true);

        $this->assertFalse($request->isSourceEnabled('my_source'));
    }

    public function test_is_source_enabled_is_case_sensitive(): void
    {
        $request = new LintRequest(['.'], [], 0, ['My_Source'], true, true, true);

        $this->assertFalse($request->isSourceEnabled('My_Source'));
        $this->assertTrue($request->isSourceEnabled('my_source'));
    }

    public function test_get_disabled_sources_returns_empty_array_by_default(): void
    {
        $request = new LintRequest(['.'], [], 0);

        $this->assertSame([], $request->getDisabledSources());
    }

    public function test_get_disabled_sources_returns_configured_sources(): void
    {
        $sources = ['source1', 'source2'];
        $request = new LintRequest(['.'], [], 0, $sources);

        $this->assertSame($sources, $request->getDisabledSources());
    }
}
