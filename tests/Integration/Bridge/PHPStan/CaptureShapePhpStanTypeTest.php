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

use PHPRegex\Parser\Analysis\CaptureShape;
use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Unit\Parser\CaptureShapeAnalyzerTest;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\ConstantTypeHelper;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;

/**
 * The match shape is a PHPStan type: PHPStan reads it, and the type it reads
 * holds every $matches the engine writes, every key included. The rows are
 * the analyzer test's engine rows (a group named MARK beside a mark verb, a
 * name in one branch of a branch reset, names shared under (?J), /n).
 */
final class CaptureShapePhpStanTypeTest extends PHPStanTestCase
{
    private const FLAG_SETS = [0, \PREG_UNMATCHED_AS_NULL, \PREG_OFFSET_CAPTURE, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL];

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProviderExternal(CaptureShapeAnalyzerTest::class, 'provideEngineRows')]
    public function test_the_shape_phpstan_reads_holds_what_the_engine_writes(string $pattern, array $subjects): void
    {
        $shape = (new CaptureShapeAnalyzer())->analyze(RegexParser::create()->parse($pattern));
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);

        foreach (self::FLAG_SETS as $flags) {
            $written = $shape->matchShape($flags);
            $type = $resolver->resolve($written);
            // One array shape, or a union of shapes when the pattern splits.
            $this->assertTrue($type->isConstantArray()->yes(), \sprintf('PHPStan reads "%s" as %s.', $written, $type->describe(VerbosityLevel::precise())));

            foreach ($subjects as $subject) {
                $matches = [];
                preg_match($pattern, $subject, $matches, $flags);
                $actual = ConstantTypeHelper::getTypeFromValue($matches);

                $this->assertTrue(
                    $type->isSuperTypeOf($actual)->yes(),
                    \sprintf('%s on "%s" with flags %d wrote %s, outside %s.', $pattern, $subject, $flags, $actual->describe(VerbosityLevel::precise()), $written),
                );

                // An array shape takes extra keys as a subtype: each key the
                // engine writes must be one PHPStan knows, or reading it is
                // reported as an offset that does not exist.
                foreach (array_keys($matches) as $key) {
                    $this->assertFalse(
                        $type->hasOffsetValueType(ConstantTypeHelper::getTypeFromValue($key))->no(),
                        \sprintf('%s on "%s" with flags %d wrote key "%s", missing from %s.', $pattern, $subject, $flags, $key, $written),
                    );
                }
            }
        }
    }

    /**
     * The shape written for the facts and the cases is the type PHPStan
     * prints for it: each string below is PHPStan's own
     * describe(VerbosityLevel::precise()) of that type (PHPStan 2.2.17: a
     * list shape without its keys, an intersection and a union in PHPStan's
     * order). The written string need not be that text, it must resolve to an
     * equivalent type: an adapter resolves the string, and PHPStan prints its
     * baselines from the type.
     */
    #[Test]
    #[DataProvider('providePrintedShapes')]
    public function test_the_written_shape_is_the_type_phpstan_prints(string $pattern, int $flags, string $printed): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $written = (new CaptureShapeAnalyzer())->analyze(RegexParser::create()->parse($pattern))->matchShape($flags);

        self::assertEquivalent($resolver->resolve($printed), $resolver->resolve($written), \sprintf('%s with flags %d writes %s', $pattern, $flags, $written));
    }

    /**
     * matchShape() of a split pattern is the union of the shapes of its
     * cases, under every flag set where PHPStan keeps that union as array
     * shapes; past PHPStan's budget it is the merged shape.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProviderExternal(CaptureShapeAnalyzerTest::class, 'provideCaseEngineRows')]
    public function test_the_match_shape_is_the_union_of_the_cases(string $pattern, array $subjects): void
    {
        $shape = (new CaptureShapeAnalyzer())->analyze(RegexParser::create()->parse($pattern));
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $this->assertNotSame([], $shape->cases, \sprintf('%s must split.', $pattern));

        foreach (self::FLAG_SETS as $flags) {
            $cases = array_map(static fn (CaptureShape $case): Type => $resolver->resolve($case->matchShape($flags)), $shape->cases);
            $expected = TypeCombinator::countConstantArrayValueTypes($cases) > ConstantArrayTypeBuilder::ARRAY_COUNT_LIMIT
                ? $resolver->resolve((new CaptureShape($shape->whole, $shape->groups, $shape->marks))->matchShape($flags))
                : TypeCombinator::union(...$cases);

            self::assertEquivalent($expected, $resolver->resolve($shape->matchShape($flags)), \sprintf('%s with flags %d', $pattern, $flags));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, flags: int, printed: string}>
     */
    public static function providePrintedShapes(): iterable
    {
        // Facts. A digit run is a numeric-string, which PHPStan already reads as non-empty.
        yield 'digits, may be "0"' => ['pattern' => '/(\d+)/', 'flags' => 0, 'printed' => 'array{numeric-string, numeric-string}'];
        yield 'digits, may be "0", offsets' => ['pattern' => '/(\d+)/', 'flags' => \PREG_OFFSET_CAPTURE, 'printed' => 'array{array{numeric-string, int<0, max>}, array{numeric-string, int<0, max>}}'];
        yield 'digits, at least two' => ['pattern' => '/(\d\d+)/', 'flags' => 0, 'printed' => 'array{non-falsy-string&numeric-string, non-falsy-string&numeric-string}'];
        yield 'letters, never "0"' => ['pattern' => '/([a-z]+)/', 'flags' => 0, 'printed' => 'array{non-falsy-string, non-falsy-string}'];
        yield 'digits, may be empty' => ['pattern' => '/(\d*)/', 'flags' => 0, 'printed' => 'array{string, string}'];
        yield 'optional digits' => ['pattern' => '/(\d+)?/', 'flags' => 0, 'printed' => 'array{0: string, 1?: numeric-string}'];
        yield 'optional digits, as null' => ['pattern' => '/(\d+)?/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'printed' => 'array{string, numeric-string|null}'];
        yield 'digits under /u are not proven' => ['pattern' => '/(\d+)/u', 'flags' => 0, 'printed' => 'array{non-empty-string, non-empty-string}'];
        yield 'optional digits, at least two, as null' => ['pattern' => '/(\d\d+)?/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'printed' => 'array{string, (non-falsy-string&numeric-string)|null}'];

        // Cases. Oracle in CaptureShapeAnalyzerTest::provideCaseShapes().
        yield 'two branches' => ['pattern' => '/(a)|(b)/', 'flags' => 0, 'printed' => "array{'a', 'a'}|array{'b', '', 'b'}"];
        yield 'two branches, as null' => ['pattern' => '/(a)|(b)/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'printed' => "array{'a', 'a', null}|array{'b', null, 'b'}"];
        yield 'two branches, offsets' => ['pattern' => '/(a)|(b)/', 'flags' => \PREG_OFFSET_CAPTURE, 'printed' => "array{array{'a', int<0, max>}, array{'a', int<0, max>}}|array{array{'b', int<0, max>}, array{'', int<-1, max>}, array{'b', int<0, max>}}"];
        yield 'anchored, in a non-capturing group' => ['pattern' => '/^(?:(\d+)|([a-z]+))$/', 'flags' => 0, 'printed' => "array{non-falsy-string, '', non-falsy-string}|array{numeric-string, numeric-string}"];
        yield 'anchored, in a non-capturing group, as null' => ['pattern' => '/^(?:(\d+)|([a-z]+))$/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'printed' => 'array{non-falsy-string, null, non-falsy-string}|array{numeric-string, numeric-string, null}'];
        yield 'after a literal' => ['pattern' => '/x(?:(a)|(b))/', 'flags' => 0, 'printed' => "array{'xa', 'a'}|array{'xb', '', 'b'}"];
        yield 'optional: a case where no branch group is set' => ['pattern' => '/(?:(a)|(b))?/', 'flags' => 0, 'printed' => "array{''}|array{'a', 'a'}|array{'b', '', 'b'}"];
        yield 'optional: a case where no branch group is set, as null' => ['pattern' => '/(?:(a)|(b))?/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'printed' => "array{'', null, null}|array{'a', 'a', null}|array{'b', null, 'b'}"];
        yield 'optional, then a literal' => ['pattern' => '/(?:(a)|(b))?c/', 'flags' => 0, 'printed' => "array{'ac', 'a'}|array{'bc', '', 'b'}|array{'c'}"];
        yield 'a branch with no group' => ['pattern' => '/(a)|b/', 'flags' => 0, 'printed' => "array{'a', 'a'}|array{'b'}"];
        yield 'an empty branch' => ['pattern' => '/(a)|/', 'flags' => 0, 'printed' => "array{''}|array{'a', 'a'}"];
        yield 'a name shared under /J' => ['pattern' => '/(?<n>a)|(?<n>b)/J', 'flags' => 0, 'printed' => "array{0: 'a', n: 'a', 1: 'a'}|array{0: 'b', n: 'b', 1: '', 2: 'b'}"];
        yield 'a name shared under /J, as null' => ['pattern' => '/(?<n>a)|(?<n>b)/J', 'flags' => \PREG_UNMATCHED_AS_NULL, 'printed' => "array{0: 'a', n: 'a', 1: 'a', 2: null}|array{0: 'b', n: 'b', 1: null, 2: 'b'}"];
    }

    /**
     * Two types are equivalent when each is a supertype of the other.
     */
    private static function assertEquivalent(Type $expected, Type $actual, string $message): void
    {
        $context = \sprintf('%s: PHPStan reads %s, expected %s.', $message, $actual->describe(VerbosityLevel::precise()), $expected->describe(VerbosityLevel::precise()));

        self::assertTrue($expected->isSuperTypeOf($actual)->yes(), $context);
        self::assertTrue($actual->isSuperTypeOf($expected)->yes(), $context);
    }
}
