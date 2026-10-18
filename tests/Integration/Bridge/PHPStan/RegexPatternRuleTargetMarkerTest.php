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

use PHPRegex\Parser\RegexParser;
use PHPRegex\PHPStan\RegexPatternRule;
use PHPStan\Php\PhpVersion;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * "@regex-target" narrows the versions of PHPStan's range a call is
 * judged for, as in `regex lint`, and with a guard on PHP_VERSION_ID the
 * versions both keep: a marker that keeps none leaves its calls unjudged,
 * a malformed one judges them for the whole range. What the engine running
 * the analysis refuses is PHPStan's to report, never the rule's.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexPatternRuleTargetMarkerTest extends RuleTestCase
{
    #[Test]
    public function test_a_marker_narrows_the_versions_a_call_is_judged_for(): void
    {
        $refusal = RegexParser::create(['php_version' => '8.2', 'pcre_version' => '10.40', 'runtime_pcre_validation' => false])->validate('/(?a)\d+/');
        $this->assertFalse($refusal->isValid);
        $reason = explode("\n", (string) $refusal->error)[0];
        $message = 'Regex pattern is invalid for PHP 8.2 with PCRE2 10.40: '.$reason.(str_ends_with($reason, '.') ? '' : '.');
        $tip = null === $refusal->hint || '' === $refusal->hint ? [] : [$refusal->hint];

        $this->analyse([__DIR__.'/Fixtures/TargetMarkerFixture.php'], false === @preg_match('/(?a)\d+/', '') ? [] : [
            [$message, 25, ...$tip],
            [$message, 29, ...$tip],
        ]);
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(phpVersion: new PhpVersion(80200), phpVersionRange: ['min' => 80200, 'max' => 80599], reflectionProvider: $this->createReflectionProvider());
    }
}
