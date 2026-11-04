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

namespace PHPRegex\Tests\Integration\Bridge\Psalm;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPRegex\Psalm\PsalmInternals;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Context;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Provider\NodeDataProvider;
use Psalm\StatementsSource;
use Psalm\Storage\Assertion\Truthy;
use Psalm\Storage\Possibilities;

/**
 * Psalm keeps its internal classes from one minor release to the next only
 * as far as its own code needs: the plugin reaches them through one class,
 * behind one check, so a release that moves one leaves every call to
 * Psalm's stubs instead of failing the run.
 */
final class PsalmInternalsTest extends TestCase
{
    protected function tearDown(): void
    {
        self::forceAvailable(null);
    }

    #[Test]
    public function test_psalm_internals_are_reached_only_through_one_class(): void
    {
        $found = [];
        foreach (glob(\dirname(__DIR__, 4).'/src/Psalm/*.php') ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), 'Psalm\Internal\\')) {
                $found[] = basename($file);
            }
        }

        $this->assertSame(['PsalmInternals.php'], $found);
    }

    #[Test]
    public function test_psalm_internals_are_available_on_the_installed_psalm(): void
    {
        $this->assertTrue(PsalmInternals::available());
    }

    /**
     * Psalm 7 hands a plugin a view of the analyser's node data, not the
     * node data itself: an assertion set on the view would be lost. The
     * assertion is set on the node data the analyser reads.
     */
    #[Test]
    public function test_psalm_internals_assert_sets_both_sides_on_the_analysers_node_data(): void
    {
        $analyzer = self::analyzer();
        $call = self::call();
        $ifTrue = [new Possibilities(0, [new Truthy()])];

        $this->assertTrue(PsalmInternals::assert($analyzer, $call, $ifTrue, []));
        $this->assertSame($ifTrue, $analyzer->node_data->getIfTrueAssertions($call));
        $this->assertSame([], $analyzer->node_data->getIfFalseAssertions($call));
    }

    #[Test]
    public function test_psalm_internals_assert_without_false_side_leaves_it_unset(): void
    {
        $analyzer = self::analyzer();
        $call = self::call();

        $this->assertTrue(PsalmInternals::assert($analyzer, $call, [], null));
        $this->assertSame([], $analyzer->node_data->getIfTrueAssertions($call));
        $this->assertNull($analyzer->node_data->getIfFalseAssertions($call));
    }

    #[Test]
    public function test_psalm_internals_leave_a_source_psalm_does_not_analyse(): void
    {
        $source = $this->createStub(StatementsSource::class);

        $this->assertFalse(PsalmInternals::assert($source, self::call(), [], []));
        $this->assertFalse(PsalmInternals::analyse(new String_('/a/'), $source, new Context()));
        $this->assertTrue(PsalmInternals::functionExists($source, 'preg_match'));
        $this->assertNull(PsalmInternals::parameters($source, 'preg_replace_callback'));
    }

    #[Test]
    public function test_psalm_internals_moved_leave_every_call_to_the_stubs(): void
    {
        self::forceAvailable(false);
        $analyzer = self::analyzer();
        $call = self::call();
        $source = $this->createStub(StatementsSource::class);

        $this->assertFalse(PsalmInternals::available());
        $this->assertFalse(PsalmInternals::assert($analyzer, $call, [], []));
        $this->assertNull($analyzer->node_data->getIfTrueAssertions($call));
        $this->assertFalse(PsalmInternals::analyse(new String_('/a/'), $source, new Context()));
        $this->assertTrue(PsalmInternals::functionExists($source, 'preg_match'));
        $this->assertNull(PsalmInternals::parameters($source, 'preg_replace_callback'));
    }

    private static function call(): FuncCall
    {
        return new FuncCall(new Name('preg_match'), []);
    }

    /**
     * A statements analyser of a Psalm project, with node data of its own.
     */
    private static function analyzer(): StatementsAnalyzer
    {
        PsalmTypes::codebase();
        $file = new FileAnalyzer(ProjectAnalyzer::getInstance(), __FILE__, basename(__FILE__));
        $constructor = (new \ReflectionClass(StatementsAnalyzer::class))->getConstructor();
        self::assertNotNull($constructor);

        // Psalm 7 adds whether the statements are the file's own scope.
        return (new \ReflectionClass(StatementsAnalyzer::class))->newInstanceArgs(\array_slice([$file, new NodeDataProvider(), true], 0, $constructor->getNumberOfParameters()));
    }

    private static function forceAvailable(?bool $available): void
    {
        (new \ReflectionProperty(PsalmInternals::class, 'available'))->setValue(null, $available);
    }
}
