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

namespace PHPRegex\Tests\Unit\Visitor;

use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPUnit\Framework\TestCase;

final class SampleGeneratorExhaustiveTest extends TestCase
{
    private SampleGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new SampleGenerator();
    }

    public function test_generate_all_char_types(): void
    {
        // h, H, v, V, R, w, W, s, S, d, D
        $types = ['h', 'H', 'v', 'V', 'R', 'w', 'W', 's', 'S', 'd', 'D'];
        foreach ($types as $type) {
            $node = new CharTypeNode($type, 0, 0);
            $result = $node->accept($this->generator);
            $this->assertIsString($result);
        }
    }

    public function test_generate_all_posix_classes(): void
    {
        $classes = [
            'cntrl', 'graph', 'print', 'word',
            'blank', 'punct', 'xdigit', 'space'
        ];

        foreach ($classes as $class) {
            $node = new PosixClassNode($class, 0, 0);
            $result = $node->accept($this->generator);
            $this->assertIsString($result);
        }
    }
}
