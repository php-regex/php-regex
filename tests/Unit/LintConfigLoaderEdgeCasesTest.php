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

use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintConfigResult;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Tests\Support\LintFunctionOverrides;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LintConfigLoaderEdgeCasesTest extends TestCase
{
    use TemporaryProject;

    protected function tearDown(): void
    {
        LintFunctionOverrides::reset();
    }

    #[Test]
    public function test_load_handles_missing_cwd(): void
    {
        $loader = new LintConfigLoader();
        LintFunctionOverrides::queueGetcwd(false);

        $result = $loader->load();

        $this->assertSame([], $result->config);
        $this->assertSame([], $result->files);
    }

    #[Test]
    public function test_load_rejects_invalid_values(): void
    {
        $paths = $this->loadJson('{"paths": 1}');
        $this->assertNotNull($paths->error);

        $exclude = $this->loadJson('{"exclude": 1}');
        $this->assertNotNull($exclude->error);

        $jobs = $this->loadJson('{"jobs": "no"}');
        $this->assertNotNull($jobs->error);

        $minSavingsType = $this->loadJson('{"checks": {"optimizations": {"minSavings": "no"}}}');
        $this->assertNotNull($minSavingsType->error);

        $minSavingsValue = $this->loadJson('{"checks": {"optimizations": {"minSavings": 0}}}');
        $this->assertNotNull($minSavingsValue->error);

        $format = $this->loadJson('{"format": ""}');
        $this->assertNotNull($format->error);

        $checks = $this->loadJson('{"checks": "no"}');
        $this->assertNotNull($checks->error);

        $checkEntry = $this->loadJson('{"checks": {"redos": {"enabled": "yes"}}}');
        $this->assertNotNull($checkEntry->error);
    }

    #[Test]
    public function test_load_reads_string_list_variants(): void
    {
        $single = $this->loadJson('{"paths": "src"}');
        $this->assertSame(['src'], (new LintDefaultsBuilder())->build($single->config)['paths'] ?? null);

        $invalidType = $this->loadJson('{"paths": 123}');
        $this->assertNotNull($invalidType->error);

        $invalidEntry = $this->loadJson('{"paths": [""]}');
        $this->assertNotNull($invalidEntry->error);
    }

    private function loadJson(string $json): LintConfigResult
    {
        return (new LintConfigLoader())->load($this->makeProject(['regex.json' => $json]));
    }
}
