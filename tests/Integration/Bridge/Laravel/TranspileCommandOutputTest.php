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

namespace PHPRegex\Tests\Integration\Bridge\Laravel;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Transpiler\Target\TargetRegistry;
use PHPUnit\Framework\Attributes\Test;

/**
 * regex:transpile where the run stops before or after the pattern is read,
 * and the JSON document under --quiet.
 */
final class TranspileCommandOutputTest extends TestCase
{
    #[Test]
    public function test_console_reports_a_construct_the_target_lacks(): void
    {
        $status = Artisan::call('regex:transpile', ['pattern' => '/a++b/', '--target' => 'javascript']);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Transpilation failed: Possessive quantifiers are not supported in JavaScript.', Artisan::output());
    }

    #[Test]
    public function test_target_help_names_the_transpiler_targets(): void
    {
        $command = Artisan::all()['regex:transpile'] ?? null;
        $this->assertNotNull($command);
        $description = $command->getDefinition()->getOption('target')->getDescription();

        $this->assertSame(1, preg_match('/\(([^)]*)\)/', $description, $matches), $description);
        $named = explode(', ', $matches[1]);
        sort($named);

        $this->assertSame((new TargetRegistry())->listTargets(), $named);
    }

    #[Test]
    public function test_console_names_the_target_of_an_alias(): void
    {
        $status = Artisan::call('regex:transpile', ['pattern' => '/a+b/', '--target' => 'js']);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Target (Javascript)', Artisan::output());
    }

    #[Test]
    public function test_console_unknown_target_lists_the_transpiler_targets(): void
    {
        $status = Artisan::call('regex:transpile', ['pattern' => '/a/', '--target' => 'ruby']);
        $output = Artisan::output();

        $this->assertSame(2, $status);
        $this->assertStringContainsString("Invalid target 'ruby'. Supported targets: html, html-pattern, javascript, js, py, python", $output);
    }

    #[Test]
    public function test_an_unknown_format_is_a_usage_error(): void
    {
        // As the CLI and Symfony: `--format=xml` is a usage error, not a console run.
        $status = Artisan::call('regex:transpile', ['pattern' => '/a/', '--format' => 'xml']);
        $output = Artisan::output();

        $this->assertSame(2, $status);
        $this->assertStringContainsString('Invalid value for --format: xml. Use console or json.', $output);
        $this->assertStringNotContainsString('Target', $output);
    }

    #[Test]
    public function test_the_format_is_read_in_any_case(): void
    {
        $status = Artisan::call('regex:transpile', ['pattern' => '/a/', '--format' => 'Console']);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Target (Javascript)', Artisan::output());
    }

    #[Test]
    public function test_json_unknown_target_prints_the_usage_envelope(): void
    {
        $status = Artisan::call('regex:transpile', ['pattern' => '/a/', '--target' => 'perl', '--format' => 'json']);
        $output = Artisan::output();

        $this->assertSame(2, $status);
        $this->assertStringEndsWith("}\n", $output);
        $document = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($document);
        $this->assertSame(['error', 'stage'], array_keys($document));
        $this->assertSame('usage', $document['stage']);
    }

    #[Test]
    public function test_json_document_is_printed_under_quiet(): void
    {
        $status = Artisan::call('regex:transpile', ['pattern' => '/<b>a+/', '--format' => 'json', '--quiet' => true]);

        $this->assertSame(0, $status);
        $document = json_decode(Artisan::output(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($document);
        $this->assertSame('/<b>a+/', $document['source'] ?? null);
    }

    protected function getPackageProviders($app): array
    {
        return [
            PHPRegexServiceProvider::class,
        ];
    }
}
