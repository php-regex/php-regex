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

use PHPRegex\PHPStan\RegexTargetMarkerRule;
use PHPStan\Php\PhpVersion;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A "@regex-target" marker that cannot be read, or that keeps no version
 * of PHPStan's range, is an error on the marker's line, once per file.
 *
 * @extends RuleTestCase<RegexTargetMarkerRule>
 */
final class RegexTargetMarkerRuleTest extends RuleTestCase
{
    #[Test]
    public function test_a_malformed_or_empty_marker_is_reported_on_its_line(): void
    {
        $this->analyse([__DIR__.'/Fixtures/TargetMarkerFixture.php'], [
            ['The @regex-target marker "php>=9.0" keeps no version the project is judged for (PHP >=8.2 <8.6): the patterns it covers are not judged.', 28, 'Widen the marker to versions of the range, or remove the code it covers if it never runs there.'],
            ['The @regex-target marker holds "php>=eight", which names no PHP or PCRE2 version constraint: write "@regex-target php>=8.4", "@regex-target pcre>=10.43" or "@regex-target php>=8.4 pcre>=10.43".', 29, 'Write the versions the code runs on as a Composer constraint after "php", "pcre", or both.'],
        ]);
    }

    protected function getRule(): Rule
    {
        return new RegexTargetMarkerRule(phpVersion: new PhpVersion(80200), phpVersionRange: ['min' => 80200, 'max' => 80599]);
    }
}
