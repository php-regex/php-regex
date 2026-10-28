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

namespace PHPRegex\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The IDE completion files name methods and argument positions: each one
 * must still exist, or the editor completes and types calls the library
 * no longer takes.
 */
final class PhpStormMetaSignaturesTest extends TestCase
{
    #[Test]
    public function test_every_argument_a_meta_file_names_exists(): void
    {
        $files = glob(\dirname(__DIR__, 3).'/src/*/.phpstorm.meta.php') ?: [];
        $this->assertNotSame([], $files);

        $missing = [];
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            // expectedArguments(\C::m(), N, ...) and override(\C::m(N), ...)
            preg_match_all('/expectedArguments\(\s*\\\\([\w\\\\]+)::(\w+)\(\),\s*(\d+)/', $code, $expected, \PREG_SET_ORDER);
            preg_match_all('/override\(\s*\\\\([\w\\\\]+)::(\w+)\((\d+)\)/', $code, $overrides, \PREG_SET_ORDER);
            foreach ([...$expected, ...$overrides] as [, $class, $method, $position]) {
                $where = basename(\dirname($file)).': '.$class.'::'.$method.'(), argument '.$position;
                if (!method_exists($class, $method)) {
                    $missing[] = $where.': no such method';

                    continue;
                }

                if ((new \ReflectionMethod($class, $method))->getNumberOfParameters() <= (int) $position) {
                    $missing[] = $where.': no such argument';
                }
            }
        }

        $this->assertSame([], $missing);
    }
}
