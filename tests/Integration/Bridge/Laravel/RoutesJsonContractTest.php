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
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Tests\Support\JsonContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * regex:routes --format=json prints the document the JSON output reference
 * describes: one JSON document on stdout, every key snake_case, slashes and
 * Unicode written as they are.
 */
final class RoutesJsonContractTest extends LaravelTestCase
{
    /**
     * @param array<string, bool|string> $options
     */
    #[Test]
    #[DataProvider('provideRuns')]
    public function test_the_report_follows_the_contract(array $options, int $exitCode): void
    {
        Route::get('/x/{v}', static fn (): string => '')->where('v', '(unclosed')->name('broken');
        Route::get('/café/{id}', static fn (): string => '')->where('id', '\d+')->name('numeric');
        Route::get('/plain', static fn (): string => '');

        $status = Artisan::call('regex:routes', ['--format' => 'json', ...$options]);
        $output = Artisan::output();

        $this->assertSame($exitCode, $status, $output);
        $document = JsonContract::decodeDocument($output);
        JsonContract::assertShape('laravel_routes', $document);
        JsonContract::assertSnakeCaseKeys($document);
        $this->assertStringContainsString('"café/{id}"', $output, 'Slashes and Unicode are written as they are.');
    }

    /**
     * @return iterable<string, array{options: array<string, bool|string>, exitCode: int}>
     */
    public static function provideRuns(): iterable
    {
        yield 'listed' => ['options' => [], 'exitCode' => 0];
        yield 'validated' => ['options' => ['--validate' => true], 'exitCode' => 1];
        yield 'format in upper case' => ['options' => ['--format' => 'JSON', '--validate' => true], 'exitCode' => 1];
    }

    #[Test]
    public function test_an_invalid_constraint_is_an_issue(): void
    {
        Route::get('/x/{v}', static fn (): string => '')->where('v', '(unclosed')->name('broken');

        Artisan::call('regex:routes', ['--format' => 'json', '--validate' => true]);
        $document = JsonContract::decodeDocument(Artisan::output());

        $issues = JsonContract::asArray($document['issues']);
        $this->assertCount(1, $issues);
        $issue = JsonContract::asArray($issues[0]);
        $this->assertSame(['broken', 'v', '(unclosed'], [$issue['route'], $issue['parameter'], $issue['pattern']]);
        $this->assertIsString($issue['error']);
        $this->assertSame(1, JsonContract::asArray($document['summary'])['total_issues']);
    }

    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }
}
