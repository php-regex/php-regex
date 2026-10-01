<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Exception;

use PhpRegex\Parser\Exception\ExceptionInterface;
use PhpRegex\Tests\Support\LibrarySource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the library throws says whose mistake it is. The caller's mistake
 * (a pattern, an option, a format name, a worker or an output that failed)
 * implements ExceptionInterface, so one catch holds every one of
 * them. A library bug is a plain \LogicException: no caller catches it on
 * purpose, and none should.
 */
final class ThrownExceptionsTest extends TestCase
{
    #[Test]
    public function test_every_thrown_exception_is_the_library_s_or_a_plain_logic_exception(): void
    {
        $offending = [];
        foreach (LibrarySource::files() as $path => $tokens) {
            foreach (LibrarySource::thrownClasses($tokens) as $thrown) {
                $class = $thrown['class'];
                if (\LogicException::class === $class) {
                    continue;
                }

                if (class_exists($class) && is_subclass_of($class, ExceptionInterface::class)) {
                    continue;
                }

                $offending[] = $path.':'.$thrown['line'].' '.$class;
            }
        }

        $this->assertSame([], $offending);
    }

    #[Test]
    public function test_the_scan_resolves_imports_aliases_and_the_namespace(): void
    {
        $tokens = token_get_all(<<<'PHP'
            <?php
            namespace Acme\Tool;

            use Other\Failure;
            use Other\Deep\Problem as Trouble;
            use function strlen;

            final class Thing
            {
                use SomeTrait;

                public function run(): void
                {
                    $f = function () use ($x): void {};
                    throw new \RuntimeException('a');
                    throw new Failure('b');
                    throw new Trouble('c');
                    throw new Local('d');
                    throw new Sub\Named('e');
                    throw new self('f');
                    $g = fn () => throw new \LogicException('g');
                }
            }
            PHP);

        $this->assertSame([
            ['class' => 'RuntimeException', 'line' => 15],
            ['class' => 'Other\\Failure', 'line' => 16],
            ['class' => 'Other\\Deep\\Problem', 'line' => 17],
            ['class' => 'Acme\\Tool\\Local', 'line' => 18],
            ['class' => 'Acme\\Tool\\Sub\\Named', 'line' => 19],
            ['class' => 'LogicException', 'line' => 21],
        ], LibrarySource::thrownClasses($tokens));
    }
}
