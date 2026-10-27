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

namespace PHPRegex\Tests\Unit\Node;

use PHPRegex\Parser\Node\NodeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every node declares accept() without a native return type: the visitor's
 * result type is the template the interface documents, which a native
 * "mixed" would only repeat less precisely.
 */
final class AcceptReturnTypeContractTest extends TestCase
{
    /**
     * @param class-string<NodeInterface> $class
     */
    #[Test]
    #[DataProvider('provideNodeClasses')]
    public function test_accept_has_no_native_return_type(string $class): void
    {
        $this->assertNull((new \ReflectionMethod($class, 'accept'))->getReturnType(), $class);
    }

    #[Test]
    public function test_every_node_declaring_accept_is_checked(): void
    {
        $this->assertCount(29, [...self::provideNodeClasses()]);
    }

    /**
     * @return iterable<string, array{class: class-string<NodeInterface>}>
     */
    public static function provideNodeClasses(): iterable
    {
        $files = glob(\dirname(__DIR__, 3).'/src/Parser/Node/*.php');
        self::assertIsArray($files);
        sort($files);

        foreach ($files as $file) {
            $source = file_get_contents($file);
            if (false === $source || !str_contains($source, 'public function accept(')) {
                continue;
            }

            $class = 'PHPRegex\\Parser\\Node\\'.basename($file, '.php');
            if (NodeInterface::class === $class) {
                continue;
            }

            self::assertTrue(is_subclass_of($class, NodeInterface::class), $class);

            yield basename($file, '.php') => ['class' => $class];
        }
    }
}
