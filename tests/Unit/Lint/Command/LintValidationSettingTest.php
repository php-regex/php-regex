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

namespace PHPRegex\Tests\Unit\Lint\Command;

use PHPRegex\Linter\Config\LintArguments;
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * checks.validation in regex.json is an object, as every other check: its
 * enabled key turns the report of the patterns PCRE2 refuses on or off, on
 * unless the file says otherwise.
 */
final class LintValidationSettingTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    public function test_the_check_is_on_by_default(): void
    {
        $this->assertTrue($this->argumentsFrom('{}')->checkValidation);
        $this->assertTrue($this->argumentsFrom('{"checks": {"validation": {}}}')->checkValidation);
    }

    #[Test]
    public function test_regex_json_turns_the_check_off_and_on(): void
    {
        $this->assertFalse($this->argumentsFrom('{"checks": {"validation": {"enabled": false}}}')->checkValidation);
        $this->assertTrue($this->argumentsFrom('{"checks": {"validation": {"enabled": true}}}')->checkValidation);
    }

    /**
     * The boolean the other checks refuse is refused here too, the object
     * it means named.
     */
    #[Test]
    #[DataProvider('provideBooleans')]
    public function test_a_boolean_is_refused_with_the_object_it_means(string $boolean): void
    {
        $error = (new LintConfigLoader())->load($this->makeProject(['regex.json' => '{"checks": {"validation": '.$boolean.'}}']))->error;

        $this->assertNotNull($error);
        $this->assertStringContainsString('Invalid "checks.validation" in ', (string) $error);
        $this->assertStringContainsString('regex.json: expected an object (write {"enabled": '.$boolean.'}).', (string) $error);
    }

    /**
     * @return iterable<string, array{boolean: string}>
     */
    public static function provideBooleans(): iterable
    {
        yield 'on' => ['boolean' => 'true'];
        yield 'off' => ['boolean' => 'false'];
    }

    private function argumentsFrom(string $json): LintArguments
    {
        $loaded = (new LintConfigLoader())->load($this->makeProject(['regex.json' => $json]));
        $this->assertNull($loaded->error);

        return LintArguments::fromDefaults((new LintDefaultsBuilder())->build($loaded->config));
    }
}
