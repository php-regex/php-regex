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

namespace PHPRegex\Tests\Tools\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * mixed-types.neon, which the repository's phpstan.dist.neon includes,
 * registers the check with the baseline and the paths its "noNewMixed"
 * parameter names; the shipped extension.neon knows nothing of it.
 *
 * @extends RuleTestCase<NoNewMixedRule>
 */
final class NoNewMixedNeonTest extends RuleTestCase
{
    public static function getAdditionalConfigFiles(): array
    {
        // PHPStan reads no path of this parameter relative to the file: it
        // is written with the fixtures' absolute paths.
        $neon = sys_get_temp_dir().'/php-regex-no-new-mixed-'.hash('xxh128', __DIR__).'.neon';
        file_put_contents($neon, \sprintf(
            "includes:\n    - %s\n\nparameters:\n    noNewMixed:\n        baseline: %s\n        paths:\n            - %s\n",
            __DIR__.'/mixed-types.neon',
            __DIR__.'/Fixtures/NoNewMixed/baseline-exact.php',
            __DIR__.'/Fixtures/NoNewMixed',
        ));

        return [
            ...parent::getAdditionalConfigFiles(),
            $neon,
        ];
    }

    #[Test]
    public function test_the_check_reads_the_baseline_the_parameter_names(): void
    {
        $this->assertCount(1, array_filter(
            self::getContainer()->getServicesByTag('phpstan.rules.rule'),
            static fn (mixed $rule): bool => $rule instanceof NoNewMixedRule,
        ));
        $this->assertCount(1, array_filter(
            self::getContainer()->getServicesByTag('phpstan.collector'),
            static fn (mixed $collector): bool => $collector instanceof MixedTypeCollector,
        ));

        $this->analyse([__DIR__.'/Fixtures/NoNewMixed/MixedDeclarations.php'], []);
    }

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(NoNewMixedRule::class);
    }

    protected function getCollectors(): array
    {
        return [self::getContainer()->getByType(MixedTypeCollector::class)];
    }
}
