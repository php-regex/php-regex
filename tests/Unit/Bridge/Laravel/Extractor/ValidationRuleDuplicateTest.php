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

namespace PHPRegex\Tests\Unit\Bridge\Laravel\Extractor;

use PHPRegex\Laravel\Extractor\ValidationRulePatternSource;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\PhpFilePatternSource;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A 'regex:' validation rule is a pattern of its own, at its own literal:
 * a preg_match() of the same pattern in the same file does not hide it, so
 * an ignore comment on the preg_match() line silences that call alone.
 */
final class ValidationRuleDuplicateTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    public function test_a_validation_rule_and_a_preg_match_of_one_pattern_are_both_kept(): void
    {
        $project = $this->makeProject(['app/Check.php' => <<<'PHP'
            <?php

            preg_match('/^[a-z]+$/', $s);
            $rules = ['slug' => 'regex:/^[a-z]+$/'];

            PHP]);

        $collection = new PatternSourceCollection([
            new PhpFilePatternSource(new PatternExtractor(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()))),
            new ValidationRulePatternSource(),
        ]);

        $read = array_map(
            static fn (PatternOccurrence $occurrence): string => $occurrence->line.' '.$occurrence->pattern,
            array_values(array_filter($collection->collect(new PatternSourceContext([$project.'/app'], [])), static fn (PatternOccurrence $occurrence): bool => null === $occurrence->ignores)),
        );

        $this->assertSame(['3 /^[a-z]+$/', '4 /^[a-z]+$/'], $read);
    }
}
