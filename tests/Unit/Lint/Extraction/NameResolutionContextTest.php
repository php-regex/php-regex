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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PHPRegex\Linter\Extraction\NameResolutionContext;
use PHPUnit\Framework\TestCase;

final class NameResolutionContextTest extends TestCase
{
    public function test_an_unimported_class_belongs_to_the_current_namespace(): void
    {
        $context = new NameResolutionContext();
        $context->enterNamespace('App\\Service');

        $this->assertSame('App\\Service\\Preg', $context->resolveClass('Preg'));
        $this->assertSame('App\\Service\\Nested\\Preg', $context->resolveClass('Nested\\Preg'));
    }

    public function test_an_imported_class_resolves_to_its_target(): void
    {
        $context = new NameResolutionContext();
        $context->enterNamespace('App\\Service');
        $context->importClass('Preg', 'Composer\\Pcre\\Preg');
        $context->importClass('Rx', 'Composer\\Pcre\\Regex');

        $this->assertSame('Composer\\Pcre\\Preg', $context->resolveClass('Preg'));
        $this->assertSame('Composer\\Pcre\\Preg', $context->resolveClass('PREG'));
        $this->assertSame('Composer\\Pcre\\Regex', $context->resolveClass('Rx'));
    }

    public function test_a_fully_qualified_name_ignores_imports(): void
    {
        $context = new NameResolutionContext();
        $context->enterNamespace('App\\Service');
        $context->importClass('Preg', 'Composer\\Pcre\\Preg');

        $this->assertSame('Other\\Preg', $context->resolveClass('\\Other\\Preg'));
    }

    public function test_a_relative_name_is_qualified_with_the_namespace(): void
    {
        $context = new NameResolutionContext();
        $context->enterNamespace('App\\Service');

        $this->assertSame('App\\Service\\Preg', $context->resolveClass('namespace\\Preg'));
    }

    public function test_entering_a_namespace_drops_the_previous_imports(): void
    {
        $context = new NameResolutionContext();
        $context->importClass('Preg', 'Composer\\Pcre\\Preg');
        $context->enterNamespace('App\\Other');

        $this->assertSame('App\\Other\\Preg', $context->resolveClass('Preg'));
    }

    public function test_an_unqualified_function_keeps_its_global_name(): void
    {
        $context = new NameResolutionContext();
        $context->enterNamespace('App\\Service');

        // PHP falls back to the global function when the namespace has none.
        $this->assertSame('preg_match', $context->resolveFunction('preg_match'));
    }

    public function test_an_imported_function_resolves_to_its_target(): void
    {
        $context = new NameResolutionContext();
        $context->enterNamespace('App\\Service');
        $context->importFunction('safeMatch', 'Safe\\preg_match');

        $this->assertSame('Safe\\preg_match', $context->resolveFunction('safeMatch'));
        $this->assertSame('Safe\\preg_split', $context->resolveFunction('\\Safe\\preg_split'));
    }

    public function test_a_qualified_function_uses_the_class_import_rules(): void
    {
        $context = new NameResolutionContext();
        $context->enterNamespace('App\\Service');
        $context->importClass('Helpers', 'Vendor\\Helpers');

        $this->assertSame('Vendor\\Helpers\\check', $context->resolveFunction('Helpers\\check'));
        $this->assertSame('App\\Service\\Other\\check', $context->resolveFunction('Other\\check'));
    }
}
