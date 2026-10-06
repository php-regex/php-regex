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
 * A preg_match() a string function answers alike is reported with the
 * function, once the automata prove they agree on every subject: never for
 * "/^foo$/", which also takes "foo\n", nor for a call that fills $matches,
 * nor for a pattern whose answer moves with the locale (shorthand and POSIX
 * classes, caseless matching, a raw high byte in extended mode), that sets
 * how PCRE runs it (a leading verb), or that reaches one string along two
 * paths (the engine may fail on its backtrack limit).
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexParserRuleTrivialMatchTest extends RuleTestCase
{
    private const FIXTURE = __DIR__.'/Fixtures/TrivialMatchFixture.php';

    #[Test]
    public function test_a_string_function_in_disguise_is_reported(): void
    {
        $errors = array_values(array_filter(
            $this->gatherAnalyserErrors([self::FIXTURE]),
            static fn (Error $error): bool => RegexPatternRule::IDENTIFIER_TRIVIAL_MATCH === $error->getIdentifier(),
        ));
        usort($errors, static fn (Error $a, Error $b): int => $a->getLine() <=> $b->getLine());

        $this->assertSame([
            [23, "preg_match() with /^https:/ is str_starts_with(\$subject, 'https:')."],
            [24, "preg_match() with /^(?:GET|POST)\\z/ is in_array(\$this->method(), ['GET', 'POST'], true)."],
            [25, "preg_match() with /^foo$/ is in_array(\$subject, ['foo', \"foo\\n\"], true)."],
        ], array_map(static fn (Error $error): array => [$error->getLine(), $error->getMessage()], $errors));

        $this->assertStringContainsString('preg_match() returns 1 or 0', (string) $errors[0]->getTip());
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: [
            'checks' => [
                'lint' => ['enabled' => false],
                'redos' => ['enabled' => false],
                'optimizations' => ['enabled' => true],
            ],
        ]);
    }
}
