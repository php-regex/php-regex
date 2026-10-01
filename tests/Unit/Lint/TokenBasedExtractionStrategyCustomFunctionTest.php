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

use PhpRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPUnit\Framework\TestCase;

final class TokenBasedExtractionStrategyCustomFunctionTest extends TestCase
{
    public function test_extracts_pattern_from_custom_function(): void
    {
        $strategy = new TokenBasedExtractionStrategy(['myCustomRegex']);

        $file = __DIR__.'/../../Fixtures/Extractor/custom_function.php';

        $result = $strategy->extract([$file]);

        $this->assertCount(1, $result);
        $this->assertSame('/test/', $result[0]->pattern);
        $this->assertSame('myCustomRegex()', $result[0]->source);
    }

    public function test_extracts_pattern_from_case_insensitive_custom_function(): void
    {
        $strategy = new TokenBasedExtractionStrategy(['MyCustomRegex']);

        $file = __DIR__.'/../../Fixtures/Extractor/case_insensitive_custom_function.php';

        $result = $strategy->extract([$file]);

        $this->assertCount(1, $result);
        $this->assertSame('/test/', $result[0]->pattern);
        // The call site spells it differently; reports use the declared name.
        $this->assertSame('MyCustomRegex()', $result[0]->source);
    }

    public function test_extracts_pattern_from_static_method(): void
    {
        $strategy = new TokenBasedExtractionStrategy(['MyClass::validate']);

        $file = __DIR__.'/../../Fixtures/Extractor/static_method.php';

        $result = $strategy->extract([$file]);

        $this->assertCount(1, $result);
        $this->assertSame('/test/', $result[0]->pattern);
        $this->assertSame('MyClass::validate()', $result[0]->source);
    }

    public function test_extracts_pattern_from_case_insensitive_static_method(): void
    {
        $strategy = new TokenBasedExtractionStrategy(['MyClass::Validate']);

        $file = __DIR__.'/../../Fixtures/Extractor/case_insensitive_static_method.php';

        $result = $strategy->extract([$file]);

        $this->assertCount(1, $result);
        $this->assertSame('/test/', $result[0]->pattern);
        $this->assertSame('MyClass::Validate()', $result[0]->source);
    }

    public function test_handles_multiple_custom_functions(): void
    {
        $strategy = new TokenBasedExtractionStrategy(['customRegex1', 'customRegex2', 'MyClass::check']);

        $file = __DIR__.'/../../Fixtures/Extractor/multiple_custom_functions.php';

        $result = $strategy->extract([$file]);

        $this->assertCount(3, $result);
        $this->assertSame('/pattern1/', $result[0]->pattern);
        $this->assertSame('/pattern2/', $result[1]->pattern);
        $this->assertSame('/pattern3/', $result[2]->pattern);
    }

    public function test_ignores_empty_custom_function_name(): void
    {
        $strategy = new TokenBasedExtractionStrategy(['', 'myCustomRegex']);

        $file = __DIR__.'/../../Fixtures/Extractor/custom_function.php';

        $result = $strategy->extract([$file]);

        $this->assertCount(1, $result);
        $this->assertSame('/test/', $result[0]->pattern);
    }

    public function test_ignores_non_string_custom_function_names(): void
    {
        $strategy = new TokenBasedExtractionStrategy(['myCustomRegex']);

        $file = __DIR__.'/../../Fixtures/Extractor/custom_function.php';

        $result = $strategy->extract([$file]);

        $this->assertCount(1, $result);
        $this->assertSame('/test/', $result[0]->pattern);
    }

    public function test_ignores_namespaced_function_call_not_in_custom_list(): void
    {
        $strategy = new TokenBasedExtractionStrategy(['myFunction']);

        $file = __DIR__.'/../../Fixtures/Extractor/namespaced_function.php';

        $result = $strategy->extract([$file]);

        $this->assertEmpty($result);
    }

    public function test_ignores_method_call_not_in_custom_list(): void
    {
        $strategy = new TokenBasedExtractionStrategy(['MyFunction']);

        $file = __DIR__.'/../../Fixtures/Extractor/method_call.php';

        $result = $strategy->extract([$file]);

        $this->assertEmpty($result);
    }

    public function test_ignores_nullsafe_method_call(): void
    {
        $strategy = new TokenBasedExtractionStrategy(['MyFunction']);

        $file = __DIR__.'/../../Fixtures/Extractor/nullsafe_method_call.php';

        $result = $strategy->extract([$file]);

        $this->assertEmpty($result);
    }
}
