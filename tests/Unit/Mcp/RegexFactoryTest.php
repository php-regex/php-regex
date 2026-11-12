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

namespace PHPRegex\Tests\Unit\Mcp;

use PHPRegex\Mcp\Exception\TargetException;
use PHPRegex\Mcp\RegexFactory;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegexFactoryTest extends TestCase
{
    /**
     * A call may spell a version as an int (8) or a string ("8"): both name
     * one target and share one instance, built once.
     */
    #[Test]
    public function test_a_version_spelled_as_an_int_shares_the_instance_of_its_string(): void
    {
        $factory = new RegexFactory();

        $asString = $factory->for(['php_version' => '80000']);
        $asInt = $factory->for(['php_version' => 80000]);

        $this->assertSame($asString, $asInt);
        $this->assertSame($asString, $factory->for(['php_version' => '80000']), 'The same options give the instance already built.');
        $this->assertSame(80000, $asInt->target()->phpVersionId);
    }

    #[Test]
    public function test_php_7_3_is_the_oldest_target_judged(): void
    {
        $factory = new RegexFactory();

        $this->assertSame(70300, $factory->for(['php_version' => '7.3'])->target()->phpVersionId);

        try {
            $factory->for(['php_version' => '7.2']);
            $this->fail('PHP 7.2 ships PCRE1 and is refused.');
        } catch (TargetException $e) {
            $this->assertStringStartsWith('"php_version" names PHP 7.2: PHP 7.2 and earlier ship PCRE1', $e->getMessage());
        }
    }

    /**
     * A Composer constraint is a target as a single version is: the
     * facade judges for its floor, as lint judges a range from it.
     */
    #[Test]
    public function test_a_constraint_is_judged_from_its_floor(): void
    {
        $factory = new RegexFactory();

        $this->assertSame(80100, $factory->for(['php_version' => '>=8.1'])->target()->phpVersionId);
        $this->assertSame(80200, (new RegexFactory(['php_version' => '^8.2 <8.6']))->for([])->target()->phpVersionId, 'The server\'s constraint is the default.');
    }

    /**
     * Two spellings of one range name one target: they share the facade,
     * and its target, however the constraint is written.
     *
     * @param list<string> $spellings
     */
    #[Test]
    #[DataProvider('provideSpellingsOfOneTarget')]
    public function test_spellings_of_one_target_share_the_instance(array $spellings): void
    {
        $factory = new RegexFactory();
        $first = $factory->for(['php_version' => $spellings[0]]);

        foreach ($spellings as $spelling) {
            $regex = $factory->for(['php_version' => $spelling]);

            $this->assertSame($first, $regex, $spelling.' names the target of '.$spellings[0]);
            $this->assertSame($factory->targetOf($first), $factory->targetOf($regex), $spelling);
        }
    }

    /**
     * @return iterable<string, array{spellings: list<string>}>
     */
    public static function provideSpellingsOfOneTarget(): iterable
    {
        // Every version a rule changes at from 8.1 is below 9.0: the upper
        // bound leaves none out.
        yield 'a constraint from 8.1' => ['spellings' => ['>=8.1', '>= 8.1', '>=8.1.0', '>=8.1 <9.0']];
        yield 'a single version' => ['spellings' => ['8.2', '8.2.0', '80200']];
    }

    /**
     * Each facade gives back the target it was built for, among the
     * several the session holds.
     */
    #[Test]
    public function test_each_facade_gives_the_target_it_was_built_for(): void
    {
        $factory = new RegexFactory(['pcre_version' => '10.42']);
        $facades = [];
        foreach (['7.4', '8.1', '>=8.3', '8.4'] as $version) {
            $facades[$version] = $factory->for(['php_version' => $version]);
        }

        $this->assertCount(4, array_unique(array_map(spl_object_id(...), $facades)), 'Four targets, four facades.');
        foreach ($facades as $version => $facade) {
            $target = $factory->targetOf($facade);

            $this->assertSame($facade->target()->phpVersionId, $target->target()->phpVersionId, $version);
            $this->assertSame($facade->target()->key(), $target->range()[0]->key(), $version.': the floor of its range');
        }
        $this->assertCount(1, $factory->targetOf($facades['8.4'])->range(), 'A single version is its own range.');
        $this->assertGreaterThan(1, \count($factory->targetOf($facades['>=8.3'])->range()), 'A constraint judges the versions above its floor.');
    }

    /**
     * Where the version comes from is part of the target the tool reports:
     * the same version from the call and from the server are two targets.
     */
    #[Test]
    public function test_the_source_of_a_version_tells_two_targets_apart(): void
    {
        $factory = new RegexFactory(['php_version' => '8.2']);

        $fromServer = $factory->targetOf($factory->for([]));
        $fromCall = $factory->targetOf($factory->for(['php_version' => '8.2']));

        $this->assertSame('--php-version', $fromServer->source());
        $this->assertSame('"php_version"', $fromCall->source());
    }

    /**
     * The target of a facade the factory did not build is a bug of the
     * caller, refused as such.
     */
    #[Test]
    public function test_the_target_of_a_facade_built_elsewhere_is_refused(): void
    {
        $this->expectException(TargetException::class);

        (new RegexFactory())->targetOf(Regex::create());
    }

    #[Test]
    public function test_the_servers_target_is_the_default_of_a_call(): void
    {
        $factory = new RegexFactory(['php_version' => '8.2', 'pcre_version' => '10.40']);

        $this->assertSame(80200, $factory->for([])->target()->phpVersionId);
        $this->assertSame(80300, $factory->for(['php_version' => '8.3'])->target()->phpVersionId, 'A call names its own version.');
    }
}
