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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\DependencyInjection;

use PHPRegex\Tests\Support\BundleContainer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

/**
 * Several config files (config/packages/php_regex.yaml, then a when@
 * section): an extraction list names exactly the calls read, so the last
 * file's list replaces the earlier ones; paths and exclude add up, as
 * Symfony merges a list by default.
 */
final class ConfigurationMergeTest extends TestCase
{
    #[Test]
    public function test_a_later_extraction_list_replaces_the_earlier_one(): void
    {
        $extraction = $this->process([
            ['extraction' => ['interop' => ['nette-utils', 'composer-pcre'], 'functions' => ['App\grep#1']]],
            ['extraction' => ['interop' => ['phpunit'], 'functions' => ['App\match#0']]],
        ])['extraction'] ?? null;

        $this->assertIsArray($extraction);
        $this->assertSame(['phpunit'], $extraction['interop'] ?? null);
        $this->assertSame(['App\match#0'], $extraction['functions'] ?? null);
    }

    #[Test]
    public function test_a_later_file_without_the_list_keeps_the_earlier_one(): void
    {
        $extraction = $this->process([
            ['extraction' => ['interop' => ['nette-utils'], 'functions' => ['App\grep#1']]],
            ['paths' => ['lib']],
        ])['extraction'] ?? null;

        $this->assertIsArray($extraction);
        $this->assertSame(['nette-utils'], $extraction['interop'] ?? null);
        $this->assertSame(['App\grep#1'], $extraction['functions'] ?? null);
    }

    #[Test]
    public function test_paths_and_exclude_add_up(): void
    {
        $config = $this->process([
            ['paths' => ['src'], 'exclude' => ['vendor']],
            ['paths' => ['lib'], 'exclude' => ['var']],
        ]);

        $this->assertSame(['src', 'lib'], $config['paths'] ?? null);
        $this->assertSame(['vendor', 'var'], $config['exclude'] ?? null);
    }

    /**
     * @param list<array<string, mixed>> $configs
     *
     * @return array<mixed> the processed configuration, as Processor gives it
     */
    private function process(array $configs): array
    {
        return (new Processor())->processConfiguration(BundleContainer::configuration(), $configs);
    }
}
