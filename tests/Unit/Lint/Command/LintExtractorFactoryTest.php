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
use PHPRegex\Linter\Config\LintExtractorFactory;
use PHPRegex\Linter\Extraction\PatternFunction;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Tests\Support\LintFunctionOverrides;
use PHPUnit\Framework\TestCase;

final class LintExtractorFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        LintFunctionOverrides::reset();
    }

    public function test_create_falls_back_to_token_extractor_when_php_parser_missing(): void
    {
        LintFunctionOverrides::queueClassExists(false);

        $factory = new LintExtractorFactory();
        $extractor = $factory->create();

        $ref = new \ReflectionClass($extractor);
        $property = $ref->getProperty('extractor');

        $inner = $property->getValue($extractor);

        $this->assertInstanceOf(TokenBasedExtractionStrategy::class, $inner);
    }

    public function test_create_builds_the_registry_from_the_arguments(): void
    {
        $factory = new LintExtractorFactory();

        $arguments = LintArguments::fromDefaults([
            'interop' => ['nette-utils'],
            'patternFunctions' => ['App\\Support\\Str::matches#1'],
        ]);

        $registry = $this->registryOf($factory->create($arguments));

        $this->assertInstanceOf(PatternFunction::class, $registry->lookupMethod('Nette\\Utils\\Strings', 'match'));
        $this->assertInstanceOf(PatternFunction::class, $registry->lookupMethod('App\\Support\\Str', 'matches'));
        // Not requested, so composer/pcre is off for this run.
        $this->assertNull($registry->lookupMethod('Composer\\Pcre\\Preg', 'match'));
    }

    public function test_create_without_arguments_keeps_the_default_presets(): void
    {
        $factory = new LintExtractorFactory();

        $registry = $this->registryOf($factory->create());

        $this->assertInstanceOf(PatternFunction::class, $registry->lookupMethod('Composer\\Pcre\\Preg', 'match'));
    }

    private function registryOf(object $extractor): PatternFunctionRegistry
    {
        $strategy = (new \ReflectionClass($extractor))->getProperty('extractor')->getValue($extractor);
        $this->assertIsObject($strategy);

        $registry = (new \ReflectionClass($strategy))->getProperty('registry')->getValue($strategy);
        $this->assertInstanceOf(PatternFunctionRegistry::class, $registry);

        return $registry;
    }
}
