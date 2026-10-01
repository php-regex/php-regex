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

namespace PhpRegex\Tests\Unit\Lint\Formatter;

use PhpRegex\Linter\Formatter\FormatterRegistry;
use PhpRegex\Linter\Formatter\OutputFormatterInterface;
use PhpRegex\Linter\LintException;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;

final class FormatterRegistryTest extends TestCase
{
    private FormatterRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new FormatterRegistry();
    }

    public function test_construct_registers_default_formatters(): void
    {
        $names = $this->registry->getNames();

        $this->assertContains('console', $names);
        $this->assertContains('json', $names);
        $this->assertContains('github', $names);
        $this->assertContains('checkstyle', $names);
        $this->assertContains('junit', $names);
        $this->assertCount(5, $names);
    }

    #[DoesNotPerformAssertions]
    public function test_get_returns_registered_formatter(): void
    {
        $formatter = $this->registry->get('console');
    }

    public function test_get_throws_exception_for_unknown_formatter(): void
    {
        $this->expectException(LintException::class);
        $this->expectExceptionMessage('Formatter "unknown" not found. Available formatters: console, json, github, checkstyle, junit');

        $this->registry->get('unknown');
    }

    public function test_has_returns_true_for_registered_formatter(): void
    {
        $this->assertTrue($this->registry->has('console'));
        $this->assertTrue($this->registry->has('json'));
        $this->assertTrue($this->registry->has('github'));
        $this->assertTrue($this->registry->has('checkstyle'));
        $this->assertTrue($this->registry->has('junit'));
    }

    public function test_has_returns_false_for_unknown_formatter(): void
    {
        $this->assertFalse($this->registry->has('unknown'));
        $this->assertFalse($this->registry->has('xml'));
        $this->assertFalse($this->registry->has('html'));
    }

    public function test_register_adds_new_formatter(): void
    {
        $mockFormatter = $this->createStub(OutputFormatterInterface::class);

        $this->registry->register('custom', $mockFormatter);

        $this->assertTrue($this->registry->has('custom'));
        $this->assertSame($mockFormatter, $this->registry->get('custom'));
        $this->assertContains('custom', $this->registry->getNames());
    }

    public function test_register_overwrites_existing_formatter(): void
    {
        $originalFormatter = $this->registry->get('console');
        $mockFormatter = $this->createStub(OutputFormatterInterface::class);

        $this->registry->register('console', $mockFormatter);

        $this->assertNotSame($originalFormatter, $this->registry->get('console'));
        $this->assertSame($mockFormatter, $this->registry->get('console'));
    }

    public function test_override_adds_new_formatter(): void
    {
        $mockFormatter = $this->createStub(OutputFormatterInterface::class);

        $this->registry->override('new_formatter', $mockFormatter);

        $this->assertTrue($this->registry->has('new_formatter'));
        $this->assertSame($mockFormatter, $this->registry->get('new_formatter'));
        $this->assertContains('new_formatter', $this->registry->getNames());
    }

    public function test_override_overwrites_existing_formatter(): void
    {
        $originalFormatter = $this->registry->get('json');
        $mockFormatter = $this->createStub(OutputFormatterInterface::class);

        $this->registry->override('json', $mockFormatter);

        $this->assertNotSame($originalFormatter, $this->registry->get('json'));
        $this->assertSame($mockFormatter, $this->registry->get('json'));
    }

    public function test_get_names_returns_all_registered_names(): void
    {
        $names = $this->registry->getNames();

        $this->assertIsArray($names);
        $this->assertContains('console', $names);
        $this->assertContains('json', $names);
        $this->assertContains('github', $names);
        $this->assertContains('checkstyle', $names);
        $this->assertContains('junit', $names);

        // All should be strings
        foreach ($names as $name) {
            $this->assertIsString($name);
        }
    }

    public function test_get_names_after_register_includes_new_formatter(): void
    {
        $mockFormatter = $this->createStub(OutputFormatterInterface::class);
        $this->registry->register('test_formatter', $mockFormatter);

        $names = $this->registry->getNames();

        $this->assertContains('test_formatter', $names);
        $this->assertCount(6, $names);
    }

    public function test_register_with_empty_name(): void
    {
        $mockFormatter = $this->createStub(OutputFormatterInterface::class);

        $this->registry->register('', $mockFormatter);

        $this->assertTrue($this->registry->has(''));
        $this->assertSame($mockFormatter, $this->registry->get(''));
        $this->assertContains('', $this->registry->getNames());
    }

    public function test_register_with_special_characters_in_name(): void
    {
        $mockFormatter = $this->createStub(OutputFormatterInterface::class);

        $this->registry->register('formatter-with-dashes', $mockFormatter);

        $this->assertTrue($this->registry->has('formatter-with-dashes'));
        $this->assertSame($mockFormatter, $this->registry->get('formatter-with-dashes'));
        $this->assertContains('formatter-with-dashes', $this->registry->getNames());
    }

    public function test_get_exception_message_lists_all_formatters(): void
    {
        // Register a few more formatters
        $this->registry->register('xml', $this->createStub(OutputFormatterInterface::class));
        $this->registry->register('html', $this->createStub(OutputFormatterInterface::class));

        try {
            $this->registry->get('nonexistent');
            $this->fail('Expected exception was not thrown');
        } catch (LintException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('Formatter "nonexistent" not found', $message);
            $this->assertStringContainsString('console', $message);
            $this->assertStringContainsString('json', $message);
            $this->assertStringContainsString('github', $message);
            $this->assertStringContainsString('checkstyle', $message);
            $this->assertStringContainsString('junit', $message);
            $this->assertStringContainsString('xml', $message);
            $this->assertStringContainsString('html', $message);
        }
    }

    public function test_multiple_registrations_work(): void
    {
        $formatter1 = $this->createStub(OutputFormatterInterface::class);
        $formatter2 = $this->createStub(OutputFormatterInterface::class);
        $formatter3 = $this->createStub(OutputFormatterInterface::class);

        $this->registry->register('fmt1', $formatter1);
        $this->registry->register('fmt2', $formatter2);
        $this->registry->register('fmt3', $formatter3);

        $this->assertSame($formatter1, $this->registry->get('fmt1'));
        $this->assertSame($formatter2, $this->registry->get('fmt2'));
        $this->assertSame($formatter3, $this->registry->get('fmt3'));

        $names = $this->registry->getNames();
        $this->assertContains('fmt1', $names);
        $this->assertContains('fmt2', $names);
        $this->assertContains('fmt3', $names);
    }
}
