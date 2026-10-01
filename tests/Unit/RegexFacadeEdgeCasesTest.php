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

use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;

final class RegexFacadeEdgeCasesTest extends TestCase
{
    public function test_regex_class_instantiation(): void
    {
        $regex = Regex::create();

        $ast = $regex->parse('/test/i');

        $this->assertSame('i', $ast->flags);
        $this->assertSame('/', $ast->delimiter);
    }

    public function test_regex_can_be_created_with_options(): void
    {
        $regex = Regex::create([
            'max_pattern_length' => 5,
            'max_lookbehind_length' => 100,
            'cache' => new NullCache(),
            'redos_ignored_patterns' => [],
            'runtime_pcre_validation' => false,
            'max_recursion_depth' => 100,
            'php_version' => 80200,
        ]);

        $validation = $regex->validate('/abcdef/');
        $this->assertFalse($validation->isValid);
        $this->assertStringContainsString('maximum length', (string) $validation->error);
    }

    public function test_regex_basic_functionality(): void
    {
        $regex = Regex::create();

        // Test basic parsing
        $ast = $regex->parse('/test/');
        $this->assertSame('/', $ast->delimiter);

        // Test validation
        $validation = $regex->validate('/test/');
        $this->assertTrue($validation->isValid);

        // Test explanation
        $explanation = $regex->explain('/test/');
        $this->assertIsString($explanation);
        $this->assertNotEmpty($explanation);
    }

    #[DoesNotPerformAssertions]
    public function test_regex_can_clear_caches(): void
    {
        $regex = Regex::create();

        $regex->clearCaches();
    }
}
