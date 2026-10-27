<?php

declare(strict_types=1);

namespace PHPRegex\Build\Tests;

use PHPRegex\Parser\BackedEnum;
use PHPRegex\Parser\Cache\AstSerializer;
use PHPRegex\Parser\EnumCases;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\UnitEnum;
use PHPUnit\Framework\TestCase;

/**
 * The classes the twin writes in place of the enums behave as the enums:
 * one instance per case, from() and tryFrom() as PHP 8.1 has them, and a
 * case a cache restores is the case itself.
 */
final class EnumCasesTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<UnitEnum>}>
     */
    public function provideClasses(): iterable
    {
        foreach (EnumCases::CLASSES as $class) {
            yield $class => [$class];
        }
    }

    /**
     * @dataProvider provideClasses
     *
     * @param class-string<UnitEnum> $class
     */
    public function test_each_case_is_one_instance(string $class): void
    {
        $cases = $class::cases();
        $this->assertNotSame([], $cases);

        foreach ($cases as $position => $case) {
            $this->assertInstanceOf(UnitEnum::class, $case);
            $this->assertSame($case, [$class, $case->name]());
            $this->assertSame($case, $class::cases()[$position]);
        }
    }

    /**
     * @dataProvider provideClasses
     *
     * @param class-string<UnitEnum> $class
     */
    public function test_a_backed_case_is_found_by_its_value_as_php_8_1_finds_it(string $class): void
    {
        if (!is_subclass_of($class, BackedEnum::class)) {
            $this->assertFalse(property_exists($class, 'value'), 'A pure enum has no value.');

            return;
        }

        foreach ($class::cases() as $case) {
            $this->assertSame($case, $class::from($case->value));
            $this->assertSame($case, $class::tryFrom($case->value));
        }

        $this->assertNull($class::tryFrom('not a value of any case'));

        try {
            $class::from('not a value of any case');
            $this->fail('from() took a value no case has.');
        } catch (\ValueError $error) {
            $this->assertSame('"not a value of any case" is not a valid backing value for enum '.$class, $error->getMessage());
        }
    }

    /**
     * @dataProvider provideClasses
     *
     * @param class-string<UnitEnum> $class
     */
    public function test_a_restored_case_is_the_case_itself(string $class): void
    {
        foreach ($class::cases() as $case) {
            $restored = unserialize(serialize([$case]), ['allowed_classes' => EnumCases::CLASSES]);

            $this->assertNotSame($case, $restored[0], 'unserialize() builds a new object.');
            $this->assertSame([$case], EnumCases::shared($restored));
        }
    }

    public function test_a_tree_a_cache_gives_back_compares_as_the_tree_parsed(): void
    {
        $tree = RegexParser::create(['cache' => null])->parse('/(a)(?<n>b)(?:c)/');
        $restored = AstSerializer::unserialize(AstSerializer::serialize($tree));

        $this->assertNotNull($restored);
        $groups = $restored->pattern->getChildren();
        $this->assertInstanceOf(GroupNode::class, $groups[0]);
        $this->assertSame(GroupType::Capturing(), $groups[0]->type);
        $this->assertInstanceOf(GroupNode::class, $groups[1]);
        $this->assertSame(GroupType::Named(), $groups[1]->type);
        $this->assertInstanceOf(GroupNode::class, $groups[2]);
        $this->assertSame(GroupType::NonCapturing(), $groups[2]->type);
    }
}
