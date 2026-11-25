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
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Tests\Support\HelperCallExtractor;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * An extractor the application writes, named by "extractor" as a class or
 * a binding of the container, is the one regex:lint reads patterns with,
 * as Symfony's "extractor_service" names one: the calls it reports are
 * linted, the native calls it leaves out are not. Binding
 * ExtractorInterface in the application changes nothing.
 */
final class CustomExtractorTest extends LaravelTestCase
{
    use TemporaryProject;

    // check() is the helper HelperCallExtractor reads; preg_match() is not.
    private const CODE = "<?php\n\ncheck('/\\d+/i', \$subject);\npreg_match('/[0-9]+/i', \$subject);\n";

    /**
     * @return iterable<string, array{extractor: string}>
     */
    public static function provideExtractors(): iterable
    {
        yield 'a class' => ['extractor' => HelperCallExtractor::class];
        yield 'a binding' => ['extractor' => 'app.regex-extractor'];
    }

    #[Test]
    #[DataProvider('provideExtractors')]
    public function test_the_named_extractor_reads_the_patterns(string $extractor): void
    {
        $this->application()->singleton('app.regex-extractor', static fn (): HelperCallExtractor => new HelperCallExtractor());
        self::configOf($this->application())->set('php-regex.extractor', $extractor);

        [$status, $payload] = $this->lint();

        $this->assertSame(0, $status);
        $this->assertSame([[3, 'regex.lint.flag.useless.i']], self::issues($payload));
    }

    /**
     * @return iterable<string, array{extractor: string|null}>
     */
    public static function provideNoExtractor(): iterable
    {
        yield 'null' => ['extractor' => null];
        yield 'an empty name' => ['extractor' => ''];
    }

    #[Test]
    #[DataProvider('provideNoExtractor')]
    public function test_without_an_extractor_the_native_calls_are_read(?string $extractor): void
    {
        self::configOf($this->application())->set('php-regex.extractor', $extractor);
        self::configOf($this->application())->set('php-regex.extraction.functions', ['x#a']);
        $this->application()->bind(ExtractorInterface::class, HelperCallExtractor::class);

        [, $payload] = $this->lint();

        $this->assertSame([[4, 'regex.lint.flag.useless.i']], array_values(array_filter(self::issues($payload), static fn (array $issue): bool => 'regex.lint.flag.useless.i' === $issue[1])));

        // The extraction settings are read, their warnings included.
        Artisan::call('regex:lint', ['paths' => [$this->makeProject(['src/Pattern.php' => self::CODE]).'/src'], '--no-routes' => true, '--no-validators' => true, '--jobs' => '1']);
        $this->assertStringContainsString('Malformed pattern function "x#a"', Artisan::output());
    }

    /**
     * The extraction settings are unused beside an extractor of the
     * application's own: what they hold that the lint cannot read is no
     * warning then.
     */
    #[Test]
    public function test_the_extraction_warnings_are_left_out_beside_an_extractor(): void
    {
        self::configOf($this->application())->set('php-regex.extraction.functions', ['x#a']);
        $project = $this->makeProject(['src/Pattern.php' => self::CODE]);
        $input = ['paths' => [$project.'/src'], '--no-routes' => true, '--no-validators' => true, '--jobs' => '1'];

        Artisan::call('regex:lint', $input);
        $this->assertStringContainsString('Malformed pattern function "x#a"', Artisan::output());

        self::configOf($this->application())->set('php-regex.extractor', HelperCallExtractor::class);
        Artisan::call('regex:lint', $input);
        $this->assertStringNotContainsString('Malformed pattern function', Artisan::output());
    }

    /**
     * @return iterable<string, array{extractor: mixed, message: string}>
     */
    public static function provideUnusableExtractors(): iterable
    {
        yield 'a class that is no extractor' => ['extractor' => \stdClass::class, 'message' => '"extractor" must name a class or a binding implementing PHPRegex\Linter\Extraction\ExtractorInterface, not stdClass.'];
        yield 'nothing the container knows' => ['extractor' => 'App\Missing', 'message' => '"extractor" must name a class or a binding implementing PHPRegex\Linter\Extraction\ExtractorInterface: App\Missing is neither.'];
        yield 'no string' => ['extractor' => ['App\Extractor'], 'message' => '"extractor" must name a class or a binding implementing PHPRegex\Linter\Extraction\ExtractorInterface, not an array.'];
    }

    #[Test]
    #[DataProvider('provideUnusableExtractors')]
    public function test_an_unusable_extractor_stops_the_lint(mixed $extractor, string $message): void
    {
        self::configOf($this->application())->set('php-regex.extractor', $extractor);

        [$status, $payload] = $this->lint();

        $this->assertSame(2, $status);
        $this->assertSame(['error' => 'Invalid config/php-regex.php: '.$message, 'stage' => 'config'], $payload);
    }

    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        self::configOf($app)->set('php-regex.cache.directory', null);
        self::configOf($app)->set('php-regex.cache.store', null);
    }

    /**
     * @return array{int, array<mixed>}
     */
    private function lint(): array
    {
        $project = $this->makeProject(['src/Pattern.php' => self::CODE]);

        $status = Artisan::call('regex:lint', [
            'paths' => [$project.'/src'],
            '--format' => 'json',
            '--no-routes' => true,
            '--no-validators' => true,
            '--jobs' => '1',
        ]);
        $output = Artisan::output();
        $payload = json_decode($output, true);
        $this->assertIsArray($payload, 'output is not JSON: '.$output);

        return [$status, $payload];
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<array{mixed, mixed}>
     */
    private static function issues(array $payload): array
    {
        $issues = [];
        foreach ((array) ($payload['results'] ?? []) as $result) {
            foreach ((array) (\is_array($result) ? ($result['issues'] ?? []) : []) as $issue) {
                if (\is_array($issue)) {
                    $issues[] = [$issue['line'] ?? null, $issue['issue_id'] ?? null];
                }
            }
        }

        return $issues;
    }
}
