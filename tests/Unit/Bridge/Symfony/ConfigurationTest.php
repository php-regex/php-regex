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

namespace PhpRegex\Tests\Unit\Bridge\Symfony;

use PhpRegex\Symfony\DependencyInjection\Configuration;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function test_default_configuration(): void
    {
        $processor = new Processor();
        $configuration = new Configuration();

        /**
         * @var array{
         *     max_pattern_length: int,
         *     cache: array{
         *         pool: string|null,
         *         directory: string|null,
         *         prefix: string,
         *     },
         *     extractor_service: string|null,
         *     analysis: array{
         *         warning_threshold: int,
         *     },
         *     redos: array{
         *         enabled: bool,
         *         threshold: string,
         *         ignored_patterns: array<int, string>
         *     },
         *     automata: array{
         *         minimization_algorithm: string,
         *         determinization_algorithm: string
         *     },
         *     optimizations: array{
         *         digits: bool,
         *         word: bool,
         *         ranges: bool,
         *         canonicalize_char_classes: bool,
         *         possessive: bool,
         *         factorize: bool,
         *         min_quantifier_count: int
         *     }
         * } $config
         */
        $config = $processor->processConfiguration($configuration, []);

        $this->assertSame(Regex::DEFAULT_MAX_PATTERN_LENGTH, $config['max_pattern_length']);
        $this->assertNull($config['cache']['pool']);
        $this->assertSame('%kernel.cache_dir%/php_regex', $config['cache']['directory']);
        $this->assertSame('regex_', $config['cache']['prefix']);
        $this->assertNull($config['extractor_service']);
        $this->assertSame(50, $config['analysis']['warning_threshold']);
        // 2.0: no unread complexity score, one ignore list, under "redos".
        $this->assertArrayNotHasKey('redos_threshold', $config['analysis']);
        $this->assertSame([], $config['redos']['ignored_patterns']);
        $this->assertSame('hopcroft', $config['automata']['minimization_algorithm']);
        $this->assertSame('subset-indexed', $config['automata']['determinization_algorithm']);
        $this->assertTrue($config['optimizations']['digits']);
        $this->assertTrue($config['optimizations']['word']);
        $this->assertTrue($config['optimizations']['ranges']);
        $this->assertTrue($config['optimizations']['canonicalize_char_classes']);
        $this->assertFalse($config['optimizations']['possessive']);
        $this->assertFalse($config['optimizations']['factorize']);
        $this->assertSame(4, $config['optimizations']['min_quantifier_count']);
    }

    public function test_custom_configuration(): void
    {
        $processor = new Processor();
        $configuration = new Configuration();

        /**
         * @var array{
         *     max_pattern_length: int,
         *     cache: array{
         *         pool: string|null,
         *         directory: string|null,
         *         prefix: string,
         *     },
         *     extractor_service: string|null,
         *     analysis: array{
         *         warning_threshold: int,
         *     },
         *     redos: array{
         *         enabled: bool,
         *         threshold: string,
         *         ignored_patterns: array<int, string>
         *     },
         *     automata: array{
         *         minimization_algorithm: string,
         *         determinization_algorithm: string
         *     },
         *     optimizations: array{
         *         digits: bool,
         *         word: bool,
         *         ranges: bool,
         *         canonicalize_char_classes: bool,
         *         possessive: bool,
         *         factorize: bool,
         *         min_quantifier_count: int
         *     }
         * } $config
         */
        $config = $processor->processConfiguration($configuration, [[
            'max_pattern_length' => 10,
            'cache' => [
                'directory' => '/tmp/cache',
            ],
            'extractor_service' => 'my_custom_extractor',
            'analysis' => [
                'warning_threshold' => 1,
            ],
            'redos' => [
                'ignored_patterns' => ['foo', 'bar'],
            ],
            'automata' => [
                'minimization_algorithm' => 'moore',
                'determinization_algorithm' => 'subset',
            ],
            'optimizations' => [
                'digits' => false,
                'word' => false,
                'ranges' => false,
                'canonicalize_char_classes' => false,
                'possessive' => true,
                'factorize' => true,
                'min_quantifier_count' => 6,
            ],
        ]]);

        /*
         * @var array{
         *     max_pattern_length: int,
         *     cache: string|null,
         *     extractor_service: string|null,
         *     analysis: array{
         *         warning_threshold: int,
         *     }
         * } $config
         */

        $this->assertSame(10, $config['max_pattern_length']);
        $cacheConfig = $config['cache'];
        $this->assertSame('/tmp/cache', $cacheConfig['directory']);
        $this->assertSame('my_custom_extractor', $config['extractor_service'] ?? 'should_not_exist');
        $this->assertSame(['foo', 'bar'], $config['redos']['ignored_patterns']);
        $this->assertSame(1, $config['analysis']['warning_threshold'] ?? 0);
        $this->assertArrayNotHasKey('redos_threshold', $config['analysis']);
        $this->assertSame('moore', $config['automata']['minimization_algorithm'] ?? '');
        $this->assertSame('subset', $config['automata']['determinization_algorithm'] ?? '');
        $this->assertFalse($config['optimizations']['digits']);
        $this->assertFalse($config['optimizations']['word']);
        $this->assertFalse($config['optimizations']['ranges']);
        $this->assertFalse($config['optimizations']['canonicalize_char_classes']);
        $this->assertTrue($config['optimizations']['possessive']);
        $this->assertTrue($config['optimizations']['factorize']);
        $this->assertSame(6, $config['optimizations']['min_quantifier_count']);
    }
}
