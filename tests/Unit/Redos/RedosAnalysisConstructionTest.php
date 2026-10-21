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

namespace PHPRegex\Tests\Unit\Redos;

use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\Finding;
use PHPRegex\Redos\Hotspot;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosConfidence;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSearchCost;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Redos\RedosWitness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The ways the library builds a ReDoS analysis: a verdict once replayed is
 * the same analysis with its confirmation, and an analysis that never ran
 * says why. Each keeps every field it does not set, so a field added to the
 * analysis cannot be lost on the way.
 */
final class RedosAnalysisConstructionTest extends TestCase
{
    private const REPLACED = ['confirmation', 'confidence', 'witness', 'replayed'];

    #[Test]
    public function test_a_confirmation_keeps_every_other_field(): void
    {
        $analysis = self::everyFieldSet();
        $confirmation = new Confirmation(true, [], '0', 1000, 1000, 1, 50.0, evidence: 'backtrack_limit');
        $witness = new RedosWitness('x', 'ab', '?', false);

        $confirmed = $analysis->withConfirmation($confirmation, RedosConfidence::High, $witness, true);

        $this->assertSame($confirmation, $confirmed->confirmation);
        $this->assertSame(RedosConfidence::High, $confirmed->confidence);
        $this->assertSame($witness, $confirmed->witness);
        $this->assertTrue($confirmed->replayed);

        $kept = 0;
        foreach ((new \ReflectionClass(RedosAnalysis::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (\in_array($property->getName(), self::REPLACED, true)) {
                continue;
            }

            $this->assertSame($property->getValue($analysis), $property->getValue($confirmed), $property->getName());
            $kept++;
        }

        $this->assertSame(21, $kept, 'Every field but the four a replay sets.');
    }

    /**
     * Each field of the analysis set to a value no default gives, so that a
     * field a builder drops shows.
     */
    #[Test]
    public function test_every_field_of_the_sample_differs_from_its_default(): void
    {
        $sample = self::everyFieldSet();
        $default = new RedosAnalysis(RedosSeverity::Safe, 0);

        foreach ((new \ReflectionClass(RedosAnalysis::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ('phpVersion' === $property->getName()) {
                continue;
            }

            $this->assertNotSame($property->getValue($default), $property->getValue($sample), $property->getName());
        }
    }

    #[Test]
    #[DataProvider('provideModes')]
    public function test_a_skipped_analysis_is_safe_with_no_proof(RedosMode $mode): void
    {
        $analysis = RedosAnalysis::skipped($mode);

        $this->assertSame(RedosSeverity::Safe, $analysis->severity);
        $this->assertSame(0, $analysis->score);
        $this->assertSame(RedosConfidence::Low, $analysis->confidence);
        $this->assertSame(RedosProof::NotAnalyzed, $analysis->proof);
        $this->assertSame($mode, $analysis->mode);
        $this->assertNull($analysis->error);
        $this->assertSame([], $analysis->recommendations);
        $this->assertSame('not analyzed', $analysis->headline());
    }

    /**
     * @return iterable<string, array{mode: RedosMode}>
     */
    public static function provideModes(): iterable
    {
        foreach (RedosMode::cases() as $mode) {
            yield $mode->value => ['mode' => $mode];
        }
    }

    #[Test]
    public function test_the_analyzer_skips_with_the_analysis_off(): void
    {
        $this->assertEquals(RedosAnalysis::skipped(RedosMode::Off), (new RedosAnalyzer())->analyze('/(a+)+$/', null, RedosMode::Off));
    }

    #[Test]
    public function test_a_failed_analysis_says_why(): void
    {
        $analysis = RedosAnalysis::failed(RedosMode::Theoretical, 'The pattern is invalid.');

        $this->assertSame(RedosSeverity::Unknown, $analysis->severity);
        $this->assertSame(0, $analysis->score);
        $this->assertSame(RedosConfidence::Low, $analysis->confidence);
        $this->assertSame(RedosProof::NotAnalyzed, $analysis->proof);
        $this->assertSame('The pattern is invalid.', $analysis->error);
        $this->assertSame(['Analysis incomplete: The pattern is invalid.'], $analysis->recommendations);
        $this->assertSame('not analyzed (analysis error)', $analysis->headline());
    }

    #[Test]
    public function test_a_failed_analysis_takes_its_advice(): void
    {
        $analysis = RedosAnalysis::failed(RedosMode::Confirmed, 'Cause: detail', 'Analysis incomplete: detail');

        $this->assertSame(RedosMode::Confirmed, $analysis->mode);
        $this->assertSame('Cause: detail', $analysis->error);
        $this->assertSame(['Analysis incomplete: detail'], $analysis->recommendations);
    }

    #[Test]
    public function test_the_analyzer_fails_on_an_invalid_pattern(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/a{2,1}/');

        $this->assertSame(RegexParser::create()->validate('/a{2,1}/')->error, $analysis->error, 'The validator says why.');
        $this->assertEquals(RedosAnalysis::failed(RedosMode::Theoretical, (string) $analysis->error), $analysis);
    }

    /**
     * A pattern the parser cannot read fails with the class of the
     * exception before its message, and advice made of the message alone.
     */
    #[Test]
    public function test_the_analyzer_fails_on_a_pattern_it_cannot_parse(): void
    {
        try {
            RegexParser::create()->parse('/(/');
            $this->fail('The parser reads an unclosed group.');
        } catch (ParserException $exception) {
        }

        $analysis = (new RedosAnalyzer())->analyze('/(/', null, RedosMode::Confirmed);

        $this->assertSame($exception::class.': '.$exception->getMessage(), $analysis->error);
        $this->assertSame(['Analysis incomplete: '.$exception->getMessage()], $analysis->recommendations);
        $this->assertSame(RedosMode::Confirmed, $analysis->mode);
        $this->assertSame(RedosProof::NotAnalyzed, $analysis->proof);
    }

    private static function everyFieldSet(): RedosAnalysis
    {
        return new RedosAnalysis(
            severity: RedosSeverity::High,
            score: 8,
            vulnerablePart: 'a+',
            recommendations: ['Make it possessive.'],
            error: 'an error',
            trigger: 'quantifier +',
            confidence: RedosConfidence::Medium,
            falsePositiveRisk: 'low',
            findings: [new Finding(RedosSeverity::High, 'message', 'a+')],
            suggestedRewrite: 'a++',
            culpritNode: new LiteralNode('a', 0, 1),
            hotspots: [new Hotspot(0, 2, RedosSeverity::High, 'a+')],
            mode: RedosMode::Confirmed,
            confirmation: new Confirmation(false, [], null, null, null, 0, 0.0),
            complexity: RedosComplexity::Polynomial,
            degree: 3,
            proof: RedosProof::Proven,
            witness: new RedosWitness('', 'a', '!', false),
            replayed: false,
            abstractions: ['a{2,2000} read as a{2,}'],
            pcreVersion: '10.40' === PcreTarget::runtime()->pcreVersion ? '10.44' : '10.40',
            analysisVersion: '0',
            upperBoundDegree: 4,
            searchCost: new RedosSearchCost(2, '', 'a', '!', false),
        );
    }
}
