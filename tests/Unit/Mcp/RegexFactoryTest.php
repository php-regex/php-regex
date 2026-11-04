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

    #[Test]
    public function test_the_servers_target_is_the_default_of_a_call(): void
    {
        $factory = new RegexFactory(['php_version' => '8.2', 'pcre_version' => '10.40']);

        $this->assertSame(80200, $factory->for([])->target()->phpVersionId);
        $this->assertSame(80300, $factory->for(['php_version' => '8.3'])->target()->phpVersionId, 'A call names its own version.');
    }
}
