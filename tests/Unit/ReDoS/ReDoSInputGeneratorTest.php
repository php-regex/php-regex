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

use PhpRegex\Redos\Internal\InputGenerator;
use PhpRegex\Redos\RedosSeverity;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class ReDoSInputGeneratorTest extends TestCase
{
    private InputGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new InputGenerator();
    }

    public function test_generate_with_critical_severity(): void
    {
        $ast = Regex::create()->parse('/a+/');
        $input = $this->generator->generate($ast, '', RedosSeverity::Critical);
        $this->assertStringStartsWith(str_repeat('a', 50), $input);
        $this->assertStringEndsWith('!', $input);
    }

    public function test_generate_with_high_severity(): void
    {
        $ast = Regex::create()->parse('/b+/');
        $input = $this->generator->generate($ast, '', RedosSeverity::High);
        $this->assertStringStartsWith(str_repeat('b', 40), $input);
        $this->assertStringEndsWith('!', $input);
    }

    public function test_generate_with_medium_severity(): void
    {
        $ast = Regex::create()->parse('/c+/');
        $input = $this->generator->generate($ast, '', RedosSeverity::Medium);
        $this->assertStringStartsWith(str_repeat('c', 30), $input);
        $this->assertStringEndsWith('!', $input);
    }

    public function test_generate_with_low_severity(): void
    {
        $ast = Regex::create()->parse('/d+/');
        $input = $this->generator->generate($ast, '', RedosSeverity::Low);
        $this->assertStringStartsWith(str_repeat('d', 20), $input);
        $this->assertStringEndsWith('!', $input);
    }

    public function test_generate_with_safe_severity(): void
    {
        $ast = Regex::create()->parse('/e+/');
        $input = $this->generator->generate($ast, '', RedosSeverity::Safe);
        $this->assertStringStartsWith(str_repeat('e', 10), $input);
        $this->assertStringEndsWith('!', $input);
    }

    public function test_generate_without_severity(): void
    {
        $ast = Regex::create()->parse('/f+/');
        $input = $this->generator->generate($ast);
        $this->assertStringStartsWith(str_repeat('f', 25), $input);
        $this->assertStringEndsWith('!', $input);
    }

    public function test_generate_with_case_insensitive_flag(): void
    {
        $ast = Regex::create()->parse('/a+/i');
        $input = $this->generator->generate($ast, 'i');
        $this->assertMatchesRegularExpression('/^[aA]+$/', substr($input, 0, -1));
    }

    public function test_generate_with_complex_node(): void
    {
        $ast = Regex::create()->parse('/[abc]+/');
        $input = $this->generator->generate($ast);
        $this->assertGreaterThan(20, \strlen($input));
    }

    public function test_generate_uses_printable_char_from_set(): void
    {
        $ast = Regex::create()->parse('/[A-Z]+/');
        $input = $this->generator->generate($ast);
        $firstChar = $input[0];
        $this->assertMatchesRegularExpression('/^[A-Z]$/', $firstChar);
    }

    public function test_generate_with_set_that_only_has_non_printable(): void
    {
        $ast = Regex::create()->parse('/[\x00-\x1F]+/');
        $input = $this->generator->generate($ast);
        $this->assertStringStartsWith('a', $input);
        $this->assertStringEndsWith('!', $input);
    }
}
