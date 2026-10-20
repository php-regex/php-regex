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
use Psalm\Internal\Provider\NodeDataProvider;
use Psalm\NodeTypeProvider;
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

    #[Test]
    public function test_psalm_internals_assert_sets_both_sides_on_psalm_node_data(): void
    {
        $nodeTypes = new NodeDataProvider();
        $call = self::call();
        $ifTrue = [new Possibilities(0, [new Truthy()])];

        $this->assertTrue(PsalmInternals::assert($nodeTypes, $call, $ifTrue, []));
        $this->assertSame($ifTrue, $nodeTypes->getIfTrueAssertions($call));
        $this->assertSame([], $nodeTypes->getIfFalseAssertions($call));
    }

    #[Test]
    public function test_psalm_internals_assert_without_false_side_leaves_it_unset(): void
    {
        $nodeTypes = new NodeDataProvider();
        $call = self::call();

        $this->assertTrue(PsalmInternals::assert($nodeTypes, $call, [], null));
        $this->assertSame([], $nodeTypes->getIfTrueAssertions($call));
        $this->assertNull($nodeTypes->getIfFalseAssertions($call));
    }

    #[Test]
    public function test_psalm_internals_assert_leaves_another_node_type_provider(): void
    {
        $this->assertFalse(PsalmInternals::assert($this->createStub(NodeTypeProvider::class), self::call(), [], []));
    }

    #[Test]
    public function test_psalm_internals_leave_a_source_psalm_does_not_analyse(): void
    {
        $source = $this->createStub(StatementsSource::class);

        $this->assertFalse(PsalmInternals::analyse(new String_('/a/'), $source, new Context()));
        $this->assertTrue(PsalmInternals::functionExists($source, 'preg_match'));
        $this->assertNull(PsalmInternals::parameters($source, 'preg_replace_callback'));
    }

    #[Test]
    public function test_psalm_internals_moved_leave_every_call_to_the_stubs(): void
    {
        self::forceAvailable(false);
        $nodeTypes = new NodeDataProvider();
        $call = self::call();
        $source = $this->createStub(StatementsSource::class);

        $this->assertFalse(PsalmInternals::available());
        $this->assertFalse(PsalmInternals::assert($nodeTypes, $call, [], []));
        $this->assertNull($nodeTypes->getIfTrueAssertions($call));
        $this->assertFalse(PsalmInternals::analyse(new String_('/a/'), $source, new Context()));
        $this->assertTrue(PsalmInternals::functionExists($source, 'preg_match'));
        $this->assertNull(PsalmInternals::parameters($source, 'preg_replace_callback'));
    }

    private static function call(): FuncCall
    {
        return new FuncCall(new Name('preg_match'), []);
    }

    private static function forceAvailable(?bool $available): void
    {
        (new \ReflectionProperty(PsalmInternals::class, 'available'))->setValue(null, $available);
    }
}
