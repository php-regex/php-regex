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
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * regex:routes gives a constraint a verdict only once it validated it:
 * --show-constraints alone lists the constraints, and marked each one
 * valid although nothing had been checked.
 */
final class RoutesCommandVerdictTest extends TestCase
{
    #[Test]
    public function test_show_constraints_without_validate_gives_no_verdict(): void
    {
        Route::get('/x/{v}', static fn (): string => '')->where('v', '(unclosed')->name('broken');

        $this->assertSame(0, Artisan::call('regex:routes', ['--show-constraints' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('v = (unclosed', $output);
        $this->assertStringNotContainsString('✓', $output);
        $this->assertStringNotContainsString('✗', $output);
    }

    #[Test]
    public function test_show_constraints_with_validate_gives_each_verdict(): void
    {
        Route::get('/x/{v}', static fn (): string => '')->where('v', '(unclosed')->name('broken');
        Route::get('/y/{id}', static fn (): string => '')->where('id', '\d+')->name('numeric');

        $this->assertSame(1, Artisan::call('regex:routes', ['--show-constraints' => true, '--validate' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('✗ v = (unclosed', $output);
        $this->assertStringContainsString('✓ id = \d+', $output);
    }

    #[Test]
    public function test_json_without_validate_gives_no_verdict(): void
    {
        Route::get('/x/{v}', static fn (): string => '')->where('v', '(unclosed')->name('broken');

        $this->assertSame([null], $this->jsonVerdicts([]));
        $this->assertSame([false], $this->jsonVerdicts(['--validate' => true]));
    }

    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }

    /**
     * @param array<string, bool> $options
     *
     * @return list<mixed>
     */
    private function jsonVerdicts(array $options): array
    {
        Artisan::call('regex:routes', ['--format' => 'json', ...$options]);
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertIsArray($payload['routes'] ?? null);

        $verdicts = [];
        foreach ($payload['routes'] as $route) {
            $this->assertIsArray($route);
            // The application may register routes of its own, as Laravel
            // 12's local disk does with storage/{path}: only this test's.
            if ('broken' !== ($route['name'] ?? null)) {
                continue;
            }

            foreach ((array) ($route['constraints'] ?? []) as $constraint) {
                $this->assertIsArray($constraint);
                $this->assertArrayHasKey('valid', $constraint);
                $verdicts[] = $constraint['valid'];
            }
        }

        return $verdicts;
    }
}
