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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\Rule\LintRuleInterface;
use PHPRegex\Linter\Rule\LintRuleRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LintRuleRegistryTest extends TestCase
{
    /**
     * The default registration order is load-bearing: per node type, rules
     * run in registry order, and finish() hooks run in registry order.
     * Changing it changes the emission order of lint issues.
     */
    #[Test]
    public function test_default_registry_order_is_stable(): void
    {
        $ruleIds = array_map(
            static fn (LintRuleInterface $rule): array => $rule->getRuleIds(),
            (new LintRuleRegistry())->all(),
        );

        $this->assertSame([
            ['regex.lint.charclass.redundant'],
            ['regex.lint.charclass.suspiciousRange'],
            ['regex.lint.charclass.suspiciousPipe'],
            ['regex.lint.range.useless'],
            ['regex.lint.charclass.duplicateChars'],
            ['regex.lint.charclass.backrefAsOctal'],
            ['regex.lint.charclass.literalMetachar'],
            ['regex.lint.alternation.empty'],
            ['regex.lint.alternation.duplicateDisjunction'],
            ['regex.lint.alternation.dotNewline', 'regex.lint.alternation.overlap', 'regex.lint.overlap.charset'],
            ['regex.lint.anchor.impossible.start', 'regex.lint.anchor.impossible.end', 'regex.lint.anchor.impossible.boundary'],
            ['regex.lint.quantifier.concatenation'],
            ['regex.lint.quantifier.zero'],
            ['regex.lint.quantifier.useless'],
            ['regex.lint.quantifier.nested'],
            ['regex.lint.dotstar.nested'],
            ['regex.lint.group.quantifiedCapture'],
            ['regex.lint.flag.redundant', 'regex.lint.flag.override'],
            ['regex.lint.group.redundant'],
            ['regex.lint.backref.undefined'],
            ['regex.lint.backref.useless'],
            ['regex.lint.escape.suspicious'],
            ['regex.lint.unicode.bracedHexWithoutU'],
            ['regex.lint.unicode.propertyWithoutU'],
            ['regex.lint.unicode.shorthandWithoutU'],
            ['regex.lint.unicode.multibyteInClassWithoutU'],
            ['regex.lint.unicode.quantifiedMultibyteWithoutU'],
            ['regex.lint.quantifier.assertion'],
            ['regex.lint.quantifier.lazyEnd'],
            ['regex.lint.flag.useless.i'],
            ['regex.lint.flag.useless.s'],
            ['regex.lint.flag.useless.m'],
            // Added after the historical set, so that its emission order
            // stays as it was.
            ['regex.lint.quantifier.emptyRepeat'],
            ['regex.lint.anchor.alternationPrecedence'],
            ['regex.lint.quantifier.possessiveImpossible'],
            ['regex.lint.lookaround.impossible'],
            ['regex.lint.group.empty'],
            ['regex.lint.charclass.single'],
            ['regex.lint.literal.multipleSpaces'],
            ['regex.lint.quantifier.lazyToClass'],
        ], $ruleIds);
    }

    #[Test]
    public function test_all_45_rule_ids_are_covered(): void
    {
        $ids = [];
        foreach ((new LintRuleRegistry())->all() as $rule) {
            foreach ($rule->getRuleIds() as $id) {
                $this->assertArrayNotHasKey($id, $ids, "Rule ID {$id} registered twice");
                $ids[$id] = true;
            }
        }

        $this->assertCount(45, $ids);
    }
}
