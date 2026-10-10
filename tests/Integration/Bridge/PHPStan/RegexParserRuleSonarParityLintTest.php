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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan;

use PHPRegex\PHPStan\RegexPatternRule;
use PHPStan\Analyser\Error;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * With lint on, PHPStan reports the bug rules taken from SonarPHP under
 * their lint id, each with a link to the reference; the style and perf
 * rules stay off, as their configuration default says.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexParserRuleSonarParityLintTest extends RuleTestCase
{
    private const FIXTURE = __DIR__.'/Fixtures/SonarParityLintFixture.php';

    private const DOCS = 'https://github.com/php-regex/php-regex/blob/2.x/docs/reference/rules.md#';

    #[Test]
    public function test_the_bug_rules_reach_phpstan_and_the_opt_in_rules_do_not(): void
    {
        $identifiers = array_map(
            static fn (Error $error): string => $error->getLine().' '.$error->getIdentifier(),
            $this->gatherAnalyserErrors([self::FIXTURE]),
        );
        sort($identifiers);

        // Lines 27 to 29 hold the style and perf examples: nothing reported.
        $this->assertSame([
            '20 regex.lint.quantifier.emptyRepeat',
            '21 regex.lint.anchor.alternationPrecedence',
            '22 regex.lint.quantifier.possessiveImpossible',
            '23 regex.lint.anchor.impossible.boundary',
            '24 regex.lint.lookaround.impossible',
            '25 regex.lint.group.empty',
        ], $identifiers);
    }

    #[Test]
    public function test_each_bug_rule_links_to_the_reference(): void
    {
        $errors = $this->gatherAnalyserErrors([self::FIXTURE]);
        // One per bug rule, lines 20 to 25.
        $this->assertCount(6, $errors);

        foreach ($errors as $error) {
            $this->assertStringContainsString('Read more: '.self::DOCS, (string) $error->getTip(), (string) $error->getIdentifier());
        }
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: [
            'checks' => [
                'lint' => ['enabled' => true],
                'redos' => ['enabled' => false],
                'optimizations' => ['enabled' => false],
            ],
        ]);
    }
}
