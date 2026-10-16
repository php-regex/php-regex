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

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\PHPStan\Internal\MixedTypeCollector;
use PHPRegex\PHPStan\Internal\NoNewMixedRule;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The declared mixed types of a file are held to the baseline, symbol by
 * symbol: native types and PHPDoc types count, prose and a variable named
 * $mixed do not, a trait counts once whatever uses it, and the baseline
 * only goes down.
 *
 * @extends RuleTestCase<NoNewMixedRule>
 */
final class NoNewMixedRuleTest extends RuleTestCase
{
    private const FIXTURES = __DIR__.'/Fixtures/NoNewMixed';

    private const NS = 'PHPRegex\Tests\Integration\Bridge\PHPStan\Fixtures\NoNewMixed\\';

    private ?string $baseline = null;

    /**
     * @var list<string>
     */
    private array $paths = [self::FIXTURES];

    #[Test]
    public function test_every_declared_mixed_is_reported_without_a_baseline(): void
    {
        $this->analyse([self::FIXTURES.'/MixedDeclarations.php'], [
            [self::NS.'MixedDeclarations declares 1 mixed type where the baseline allows 0: narrow the type, or give the baseline the reason it stays mixed.', 21],
            [self::NS.'MixedDeclarations::$bag declares 1 mixed type where the baseline allows 0: narrow the type, or give the baseline the reason it stays mixed.', 28],
            [self::NS.'MixedDeclarations::__construct() declares 2 mixed types where the baseline allows 0: narrow the type, or give the baseline the reason it stays mixed.', 33],
            [self::NS.'MixedDeclarations::read() declares 4 mixed types where the baseline allows 0: narrow the type, or give the baseline the reason it stays mixed.', 35],
            [self::NS.'SharedDeclarations::shared() declares 1 mixed type where the baseline allows 0: narrow the type, or give the baseline the reason it stays mixed.', 57],
            [self::NS.'helper() declares 2 mixed types where the baseline allows 0: narrow the type, or give the baseline the reason it stays mixed.', 62],
        ]);
    }

    #[Test]
    public function test_a_baseline_entry_for_each_symbol_allows_its_count(): void
    {
        $this->baseline = self::FIXTURES.'/baseline-exact.php';

        $this->analyse([self::FIXTURES.'/MixedDeclarations.php'], []);
    }

    #[Test]
    public function test_the_baseline_only_goes_down(): void
    {
        $this->baseline = self::FIXTURES.'/baseline-loose.php';

        $this->analyse([self::FIXTURES.'/MixedDeclarations.php'], [
            ['The baseline of declared mixed types names Deleted.php, which does not exist: remove its entries.', 1],
            [self::NS.'MixedDeclarations::gone() declares no mixed type where the baseline allows 1 (json-decode): remove the entry.', 1],
            [self::NS.'MixedDeclarations::__construct() declares 2 mixed types where the baseline allows 1: narrow the type, or give the baseline the reason it stays mixed.', 33],
            [self::NS.'MixedDeclarations::read() declares 4 mixed types where the baseline allows 5 (template-identity): lower the baseline.', 35],
        ]);
    }

    #[Test]
    public function test_a_file_the_baseline_names_must_exist(): void
    {
        $this->baseline = self::FIXTURES.'/baseline-loose.php';
        $this->paths = [];

        $this->analyse([self::FIXTURES.'/MixedDeclarations.php'], [
            ['The baseline of declared mixed types names Deleted.php, which does not exist: remove its entries.', 1],
        ]);
    }

    #[Test]
    public function test_a_reason_outside_the_vocabulary_is_refused(): void
    {
        $this->baseline = self::FIXTURES.'/baseline-unknown-reason.php';
        $this->paths = [];

        $this->analyse([self::FIXTURES.'/MixedDeclarations.php'], [
            ['The baseline of declared mixed types gives '.self::NS.'helper() in MixedDeclarations.php no [reason, count]: the reasons are json-decode, host-contract, host-return, template-identity, validator-input, protocol-slot, callback-opaque, reflection-sink, open-options-bag, preg-mirror.', 1],
        ]);
    }

    #[Test]
    public function test_a_baseline_that_returns_no_array_allows_nothing(): void
    {
        $this->baseline = self::FIXTURES.'/baseline-not-an-array.php';
        $this->paths = [];

        $this->analyse([self::FIXTURES.'/MixedDeclarations.php'], [
            ['The baseline of declared mixed types, '.self::FIXTURES.'/baseline-not-an-array.php, is no PHP file returning an array.', 1],
        ]);
    }

    #[Test]
    public function test_a_baseline_that_names_no_file_stops_the_analysis(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"noNewMixed.baseline" names no file: '.self::FIXTURES.'/missing.php.');

        new NoNewMixedRule(self::FIXTURES.'/missing.php', []);
    }

    #[Test]
    public function test_a_file_outside_the_paths_is_not_judged(): void
    {
        $this->paths = [__DIR__.'/Elsewhere'];

        $this->analyse([self::FIXTURES.'/MixedDeclarations.php'], []);
    }

    /**
     * A whole analysis reads every file under the paths: one that leaves no
     * record has no mixed type left, and each of its entries goes.
     */
    #[Test]
    public function test_the_entries_of_a_file_left_with_no_mixed_type_go(): void
    {
        $this->baseline = self::FIXTURES.'/baseline-stale-file.php';

        $this->analyse([self::FIXTURES.'/MixedDeclarations.php', self::FIXTURES.'/NoMixedDeclarations.php'], [
            [self::NS.'NoMixedDeclarations::typed() declares no mixed type where the baseline allows 1 (host-return): remove the entry.', 1],
        ]);
    }

    protected function getRule(): Rule
    {
        return new NoNewMixedRule($this->baseline, $this->paths);
    }

    protected function getCollectors(): array
    {
        return [new MixedTypeCollector(self::getContainer()->getByType(Lexer::class), self::getContainer()->getByType(PhpDocParser::class))];
    }
}
