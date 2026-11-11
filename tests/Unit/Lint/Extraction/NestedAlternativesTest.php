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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A ternary, a "?:" and a "??" are read one inside another down to
 * thirty-two levels: "A ?? B ?? C" is "A ?? (B ?? C)", two levels, and a
 * chain of thirty-three values is thirty-two. Deeper, the innermost is
 * not read, and an arm that is not read leaves the whole argument unread.
 * The time a chain takes grows with its length, not with its square.
 *
 * PHP 8.4 (php -r): with $a = '/va/', $a ?? $a ?? '/x/' is "/va/", and
 * "/x/" with $a null; with $e = '', $e ?: $e ?: '/x/' is "/x/", and
 * "/va/" with $e = '/va/'; with const A = '/ka/', A ?? A ?? '/x/' is
 * "/ka/", whatever the number of A written; with $f true,
 * $f ? ($f ? '/a/' : '/b/') : '/b/' is "/a/", and "/b/" with $f false.
 *
 * Every case runs through both strategies.
 */
final class NestedAlternativesTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{argument: string, patterns: list<string>, state: string}>
     */
    public static function provideNestedAlternatives(): iterable
    {
        foreach ([[32, ['/va/', '/x/'], 'finite_set'], [33, [], 'opaque']] as [$levels, $patterns, $state]) {
            yield 'a chain of "??", '.$levels.' levels' => [
                'argument' => str_repeat('$a ?? ', $levels)."'/x/'",
                'patterns' => $patterns,
                'state' => $state,
            ];
            yield 'a chain of "?:", '.$levels.' levels' => [
                'argument' => str_repeat('$e ?: ', $levels)."'/x/'",
                'patterns' => $patterns,
                'state' => $state,
            ];
            yield 'a chain of "??" in parentheses, '.$levels.' levels' => [
                'argument' => str_repeat('$a ?? (', $levels)."'/x/'".str_repeat(')', $levels),
                'patterns' => $patterns,
                'state' => $state,
            ];
        }

        // A constant is never null: what stands after it is never taken, read or not.
        yield 'a chain of "??" after a constant, 33 levels' => [
            'argument' => str_repeat('self::A ?? ', 33)."'/x/'",
            'patterns' => ['/ka/'],
            'state' => 'constant',
        ];

        foreach ([[32, ['/a/', '/b/'], 'finite_set'], [33, [], 'opaque']] as [$levels, $patterns, $state]) {
            yield 'ternaries in their first arm, '.$levels.' levels' => [
                'argument' => str_repeat('$f ? ', $levels)."'/a/'".str_repeat(" : '/b/'", $levels),
                'patterns' => $patterns,
                'state' => $state,
            ];
            yield 'ternaries in their second arm, '.$levels.' levels' => [
                'argument' => str_repeat("\$f ? '/a/' : (", $levels - 1)."\$f ? '/a/' : '/b/'".str_repeat(')', $levels - 1),
                'patterns' => $patterns,
                'state' => $state,
            ];
        }

        // The levels of a part do not add to those of the part beside it.
        yield 'two chains side by side in a concatenation' => [
            'argument' => "'/' . (".str_repeat('self::B ?? ', 20)."'x') . (".str_repeat('self::B ?? ', 20)."'y') . '/'",
            'patterns' => ['/kbkb/'],
            'state' => 'constant',
        ];
    }

    /**
     * @param list<string> $patterns the patterns read, each once, in byte order
     */
    #[Test]
    #[DataProvider('provideNestedAlternatives')]
    public function test_alternatives_are_read_down_to_thirty_two_levels(string $argument, array $patterns, string $state): void
    {
        $code = self::classCalling($argument);

        $read = array_values(array_unique($this->patternsReadByBoth($code)));
        sort($read, \SORT_STRING);
        $this->assertSame($patterns, $read);
        $this->assertSame([$state], $this->statesReadByBoth($code));
    }

    /**
     * A ternary written after the ":" of another without parentheses.
     *
     * PHP 8.4 (php -l) refuses the file: "Unparenthesized `a ? b : c ? d : e`
     * is not supported", and the same for `a ?: b ? c : d` and
     * `a ? b : c ?: d`. PHP 7.3 (php -r) reads the two from the left:
     * $a ? '/x/' : $b ? '/y/' : '/z/' is "/y/" with $a true and never
     * "/x/"; $a ?: $b ? '/y/' : '/z/' is "/y/" or "/z/" and never $a.
     * Only "A ?: B ?: C" means the same both ways, and PHP 8 keeps it.
     *
     * @return iterable<string, array{argument: string, patterns: list<string>, state: string}>
     */
    public static function provideTernariesWrittenOneAfterTheOther(): iterable
    {
        $refused = [
            'a ternary after the ":" of a ternary' => "\$f ? '/a/' : \$f ? '/b/' : '/c/'",
            'a ternary after a "?:"' => "\$e ?: \$f ? '/b/' : '/c/'",
            'a "?:" after the ":" of a ternary' => "\$f ? '/a/' : \$e ?: '/c/'",
            'a ternary after two "?:", a constant first' => "self::A ?: \$e ?: \$f ? '/b/' : '/c/'",
            'a ternary after a "??" in the second arm' => "\$f ? '/a/' : \$a ?? \$f ? '/b/' : '/c/'",
            'a ternary after one whose condition is in parentheses' => "(\$f) ? '/a/' : \$f ? '/b/' : '/c/'",
            'a ternary after a "?:" after a ternary' => "\$f ? '/a/' : \$e ?: \$f ? '/b/' : '/c/'",
        ];
        foreach ($refused as $name => $argument) {
            yield $name => ['argument' => $argument, 'patterns' => [], 'state' => 'opaque'];
        }

        // What PHP 8 compiles is read as before.
        yield 'the second ternary in parentheses' => [
            'argument' => "\$f ? '/a/' : (\$f ? '/b/' : '/c/')",
            'patterns' => ['/a/', '/b/', '/c/'],
            'state' => 'finite_set',
        ];
        yield 'the first ternary in parentheses' => [
            'argument' => "(\$f ? '/a/' : \$f) ? '/b/' : '/c/'",
            'patterns' => ['/b/', '/c/'],
            'state' => 'finite_set',
        ];
        yield 'a ternary in the first arm of another' => [
            'argument' => "\$f ? \$f ? '/a/' : '/b/' : '/c/'",
            'patterns' => ['/a/', '/b/', '/c/'],
            'state' => 'finite_set',
        ];
        yield 'a ternary whose condition is a "??"' => [
            'argument' => "\$a ?? \$f ? '/b/' : '/c/'",
            'patterns' => ['/b/', '/c/'],
            'state' => 'finite_set',
        ];
        yield 'a ternary assigned after a "?:"' => [
            'argument' => "self::A ?: \$x = \$f ? '/b/' : '/c/'",
            'patterns' => ['/ka/'],
            'state' => 'constant',
        ];
        yield 'a ternary in an arrow function after a "?:"' => [
            'argument' => "self::A ?: fn () => \$f ? '/b/' : '/c/'",
            'patterns' => ['/ka/'],
            'state' => 'constant',
        ];
    }

    /**
     * @param list<string> $patterns the patterns read, each once, in byte order
     */
    #[Test]
    #[DataProvider('provideTernariesWrittenOneAfterTheOther')]
    public function test_a_ternary_after_another_without_parentheses_is_not_read(string $argument, array $patterns, string $state): void
    {
        $code = self::classCalling($argument);

        $read = array_values(array_unique($this->patternsReadByBoth($code)));
        sort($read, \SORT_STRING);
        $this->assertSame($patterns, $read);
        $this->assertSame([$state], $this->statesReadByBoth($code));
    }

    /**
     * @return iterable<string, array{argument: string}>
     */
    public static function provideLongChains(): iterable
    {
        yield 'four thousand "??"' => ['argument' => str_repeat("\$o['k'] ?? ", 4000)."'/x/'"];
        yield 'four thousand "?:"' => ['argument' => str_repeat('$s ?: ', 4000)."'/x/'"];
        yield 'four thousand ternaries in their first arm' => ['argument' => str_repeat('$f ? ', 4000)."'/a/'".str_repeat(" : '/b/'", 4000)];
    }

    /**
     * One reading of four thousand values is timed. Read again from each
     * value, the chain took half a minute on the machine that reads it
     * in half a second now; a run that collects the coverage is several
     * times slower for both. The limit stands between the two, far from
     * each.
     */
    #[Test]
    #[DataProvider('provideLongChains')]
    public function test_a_long_chain_is_not_read_again_for_each_of_its_values(string $argument): void
    {
        $code = self::classCalling($argument);
        $start = microtime(true);
        $patterns = $this->patternsReadByBoth($code);
        $elapsed = microtime(true) - $start;

        $this->assertSame([], $patterns);
        $this->assertSame(['opaque'], $this->statesReadByBoth($code));
        $this->assertLessThan(20.0, $elapsed, 'A chain must not be scanned again from each of its values.');
    }

    private static function classCalling(string $argument): string
    {
        return "<?php\n\nfinal class Chain\n{\n    const A = '/ka/';\n    const B = 'kb';\n\n    public function run(bool \$f, array \$o, string \$s): int\n    {\n        \$a = '/va/';\n        \$e = \$f ? '' : '/va/';\n\n        return preg_match(".$argument.", \$s);\n    }\n}\n";
    }
}
